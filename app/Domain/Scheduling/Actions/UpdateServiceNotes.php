<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Exceptions\ServiceSessionConflictException;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Support\Facades\DB;

/**
 * Update the operational service notes on a non-terminal session (Plan §25.2; Phase
 * 16C). Notes are free-text operational context — never client contact. A terminal
 * (`completed`/`cancelled`) session is immutable, so its notes cannot change. Notes
 * are not treated as a separately-audited mutation in 16C (the final specification
 * does not classify them as a distinct auditable transition); the value is trimmed
 * and stored, and surfaced through the masked resource.
 */
final class UpdateServiceNotes
{
    public function handle(ServiceSession $session, string $notes): ServiceSession
    {
        return DB::transaction(function () use ($session, $notes): ServiceSession {
            /** @var ServiceSession $locked */
            $locked = ServiceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw new ServiceSessionConflictException(
                    'service_session_terminal',
                    'A completed or cancelled service session cannot be edited.',
                    409,
                );
            }

            $clean = trim($notes);
            $locked->notes = $clean === '' ? null : $clean;
            $locked->save();

            $locked->refresh()->load(['client', 'service', 'personnel', 'queueEntry']);

            return $locked;
        });
    }
}
