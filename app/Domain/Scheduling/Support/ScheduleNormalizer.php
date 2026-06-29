<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Support;

use App\Domain\Scheduling\Enums\AvailabilityType;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Normalizes + structurally validates an availability replacement payload before
 * persistence (Phase 15B). Used by BOTH the Form Request (so the API returns the
 * canonical 422 `error.fields` envelope) and the domain action (defence-in-depth
 * inside the transaction, mirroring the DB CHECK + exclusion constraints).
 *
 * Validates: time format + half-open `start < end` (no zero-length/cross-midnight),
 * weekday range, date format, and SAME-POLARITY overlap per weekday (recurring) /
 * per date (exception). Opposite-polarity overlaps (a break inside a shift) are
 * allowed — they are resolved deterministically by AvailabilityResolver.
 */
final class ScheduleNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $recurring
     * @param  array<int, array<string, mixed>>  $exceptions
     * @return array{recurring: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function normalize(array $recurring, array $exceptions): array
    {
        $normalizedRecurring = [];
        foreach (array_values($recurring) as $i => $row) {
            $normalizedRecurring[] = $this->normalizeRecurring($row, $i);
        }

        $normalizedExceptions = [];
        foreach (array_values($exceptions) as $i => $row) {
            $normalizedExceptions[] = $this->normalizeException($row, $i);
        }

        $this->assertNoSamePolarityOverlap($normalizedRecurring, 'weekday', 'recurring');
        $this->assertNoSamePolarityOverlap($normalizedExceptions, 'date', 'exceptions');

        return ['recurring' => $normalizedRecurring, 'exceptions' => $normalizedExceptions];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRecurring(array $row, int $index): array
    {
        $weekday = filter_var($row['weekday'] ?? null, FILTER_VALIDATE_INT);
        if ($weekday === false || $weekday < 0 || $weekday > 6) {
            $this->fail("recurring.{$index}.weekday", 'Weekday must be an integer 0 (Sunday) through 6 (Saturday).');
        }

        $interval = $this->interval((string) ($row['start_time'] ?? ''), (string) ($row['end_time'] ?? ''), "recurring.{$index}");

        return [
            'type' => AvailabilityType::Recurring->value,
            'weekday' => $weekday,
            'date' => null,
            'start_time' => $interval->startString(),
            'end_time' => $interval->endString(),
            'available' => $this->boolean($row['available'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeException(array $row, int $index): array
    {
        $rawDate = (string) ($row['date'] ?? '');
        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $rawDate);
            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $rawDate) {
                throw new InvalidArgumentException('bad date');
            }
        } catch (InvalidArgumentException) {
            $this->fail("exceptions.{$index}.date", 'Date must be a valid YYYY-MM-DD business date.');
        }

        $interval = $this->interval((string) ($row['start_time'] ?? ''), (string) ($row['end_time'] ?? ''), "exceptions.{$index}");

        return [
            'type' => AvailabilityType::Exception->value,
            'weekday' => null,
            'date' => $rawDate,
            'start_time' => $interval->startString(),
            'end_time' => $interval->endString(),
            'available' => $this->boolean($row['available'] ?? null),
        ];
    }

    private function interval(string $start, string $end, string $path): TimeInterval
    {
        try {
            return TimeInterval::fromStrings($start, $end);
        } catch (InvalidArgumentException $e) {
            $this->fail("{$path}.start_time", $e->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertNoSamePolarityOverlap(array $rows, string $keyColumn, string $field): void
    {
        // Group by (weekday|date, available); within a group no two intervals may overlap.
        $groups = [];
        foreach ($rows as $index => $row) {
            $bucket = $row[$keyColumn].'|'.($row['available'] ? '1' : '0');
            $groups[$bucket][] = ['index' => $index, 'row' => $row];
        }

        foreach ($groups as $members) {
            $count = count($members);
            for ($a = 0; $a < $count; $a++) {
                for ($b = $a + 1; $b < $count; $b++) {
                    $intervalA = TimeInterval::fromStrings($members[$a]['row']['start_time'], $members[$a]['row']['end_time']);
                    $intervalB = TimeInterval::fromStrings($members[$b]['row']['start_time'], $members[$b]['row']['end_time']);
                    if ($intervalA->overlaps($intervalB)) {
                        $this->fail(
                            "{$field}.{$members[$b]['index']}",
                            'Two same-type, same-availability intervals overlap for the same '.($keyColumn === 'weekday' ? 'weekday' : 'date').'.',
                        );
                    }
                }
            }
        }
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
