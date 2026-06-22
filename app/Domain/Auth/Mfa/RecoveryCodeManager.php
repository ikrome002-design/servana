<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Domain\Auth\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates and consumes one-time MFA recovery codes (Plan §18; Phase R3).
 *
 * Codes are CSPRNG-generated, displayed once to the user, and stored only as
 * SHA-256 hashes. Consumption is a single atomic conditional UPDATE so a code
 * cannot be used twice even under concurrent submissions.
 */
final class RecoveryCodeManager
{
    /** Plaintext code format: two 5-char crockford-ish groups, e.g. `7H2KQ-9MZ4P`. */
    private const GROUP_LENGTH = 5;

    /**
     * Replace the user's recovery-code set and return the fresh PLAINTEXT codes
     * (shown once; only hashes are persisted). Old codes are removed.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $count = max(1, (int) Config::get('servana.mfa.recovery_code_count', 10));

        return DB::transaction(function () use ($user, $count): array {
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

            $plaintext = [];

            foreach (range(1, $count) as $ignored) {
                $code = $this->generateRawCode();
                $plaintext[] = $code;

                MfaRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hash($code),
                ]);
            }

            return $plaintext;
        });
    }

    /**
     * Atomically consume a recovery code for the user. Returns true on success;
     * false if the code is unknown or already used. The conditional UPDATE
     * matches only an unused row and must affect exactly one row, so concurrent
     * consumers cannot both succeed.
     */
    public function consume(User $user, string $rawCode): bool
    {
        $affected = DB::table('mfa_recovery_codes')
            ->where('user_id', $user->id)
            ->where('code_hash', $this->hash($this->normalize($rawCode)))
            ->whereNull('used_at')
            ->update([
                'used_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $affected === 1;
    }

    /** Count of still-usable codes (for the status payload). */
    public function remaining(User $user): int
    {
        return MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();
    }

    /** SHA-256 hex digest of the normalized raw code. */
    public function hash(string $rawCode): string
    {
        return hash('sha256', $rawCode);
    }

    private function normalize(string $rawCode): string
    {
        return Str::upper(trim($rawCode));
    }

    private function generateRawCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // no ambiguous 0/O/1/I/L
        $make = function () use ($alphabet): string {
            $out = '';
            for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $out;
        };

        return $make().'-'.$make();
    }
}
