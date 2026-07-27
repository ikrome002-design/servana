<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\CalendarExceptionType;
use App\Domain\Branches\Exceptions\BranchCalendarException as CalendarConflict;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\MerchantBranch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create or replace a branch calendar exception (REM-SCR-002B; Plan §7.2, §27.3 Branch Manager
 * "branch profile/calendar", Scope §3.3).
 *
 * The table, model and runtime consumer already existed: {@see
 * \App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator} is "THE single reusable
 * branch operating-calendar gate" and already honours every type (closure types → branch_closed,
 * `modified_hours` → the modified window). Only the operator surface was missing, so this action
 * writes the existing shape and invents no new semantics.
 *
 * `merchant_id` derives from the branch (the R5 composite-consistency FK), never from input.
 * `UNIQUE(branch_id, date, type)` is the DB invariant; a duplicate is surfaced as a deterministic
 * 422 rather than a 500, and the DB remains the final arbiter under concurrency.
 */
final class SetBranchCalendarException
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{date: string, type: string, opens_at?: string|null, closes_at?: string|null, reason?: string|null}  $attributes
     */
    public function handle(MerchantBranch $branch, array $attributes, User $actor): BranchCalendarException
    {
        $type = CalendarExceptionType::from($attributes['type']);

        // A closure type has no open window by definition; normalise rather than trusting input,
        // so a caller cannot persist hours that the validator would then ignore.
        $isClosure = $type !== CalendarExceptionType::ModifiedHours;

        return DB::transaction(function () use ($branch, $attributes, $type, $isClosure, $actor): BranchCalendarException {
            // ONE exception per (branch, date), regardless of type. The DB permits one row per
            // (branch, date, TYPE), but AppointmentBranchScheduleValidator::openWindowFor() looks
            // the date up with `whereDate('date', …)->first()` and takes whichever row comes back
            // — so two exceptions on one date would make the scheduling decision order-dependent.
            // The operator intent is also single-valued: a date is either closed or has modified
            // hours, never both. Constraining it here keeps that latent ambiguity unreachable
            // through the only surface that can create an exception (see docs/proof/phase-23.md).
            $existing = BranchCalendarException::query()
                ->where('branch_id', $branch->id)
                ->whereDate('date', $attributes['date'])
                ->first();

            if ($existing instanceof BranchCalendarException) {
                throw CalendarConflict::duplicate($attributes['date'], $existing->type->value);
            }

            try {
                $exception = BranchCalendarException::query()->create([
                    'merchant_id' => $branch->merchant_id,
                    'branch_id' => $branch->id,
                    'date' => $attributes['date'],
                    'type' => $type->value,
                    // Normalised to H:i:s so the CREATE response matches what a later read returns
                    // from the `time(0)` column — otherwise `11:00` in, `11:00:00` back out.
                    'opens_at' => $isClosure ? null : self::normaliseTime($attributes['opens_at'] ?? null),
                    'closes_at' => $isClosure ? null : self::normaliseTime($attributes['closes_at'] ?? null),
                    'reason' => $attributes['reason'] ?? null,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw CalendarConflict::duplicate($attributes['date'], $type->value);
                }

                throw $e;
            }

            $this->audit->record(
                AuditEvent::BranchCalendarExceptionSet,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $exception,
                [
                    'date' => $attributes['date'],
                    'type' => $type->value,
                    'closes_branch' => $isClosure,
                ],
            );

            return $exception;
        });
    }

    /**
     * Update an existing exception in place. The `date`/`type` pair is the identity the unique
     * constraint and the validator both key on, so it is NOT re-pointable — change the window,
     * the reason, or delete and re-create.
     *
     * @param  array{opens_at?: string|null, closes_at?: string|null, reason?: string|null}  $attributes
     */
    public function update(BranchCalendarException $exception, array $attributes, User $actor): BranchCalendarException
    {
        $isClosure = $exception->type !== CalendarExceptionType::ModifiedHours;

        return DB::transaction(function () use ($exception, $attributes, $isClosure, $actor): BranchCalendarException {
            /** @var BranchCalendarException $locked */
            $locked = BranchCalendarException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();

            if (! $isClosure) {
                if (array_key_exists('opens_at', $attributes)) {
                    $locked->opens_at = self::normaliseTime($attributes['opens_at']);
                }
                if (array_key_exists('closes_at', $attributes)) {
                    $locked->closes_at = self::normaliseTime($attributes['closes_at']);
                }
            }
            if (array_key_exists('reason', $attributes)) {
                $locked->reason = $attributes['reason'];
            }

            $locked->save();

            $this->audit->record(
                AuditEvent::BranchCalendarExceptionSet,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'date' => $locked->date->toDateString(),
                    'type' => $locked->type->value,
                    'closes_branch' => $isClosure,
                    'updated' => true,
                ],
            );

            return $locked;
        });
    }

    /** `H:i` or `H:i:s` → `H:i:s`, matching what the `time(0)` column reads back. */
    private static function normaliseTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return substr_count($time, ':') === 1 ? $time.':00' : $time;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // PostgreSQL unique_violation.
        return ($e->getCode() === '23505') || str_contains($e->getMessage(), 'branch_calendar_exceptions_branch_id_date_type_unique');
    }
}
