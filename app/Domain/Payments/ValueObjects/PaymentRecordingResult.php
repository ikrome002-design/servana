<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

use App\Domain\Payments\Models\PaymentRecordingGroup;

/**
 * The committed outcome of a recording action (Plan §41; Phase 18A). A successful
 * recording lands the group at `pending_validation`; a duplicate-suspected recording
 * lands (and holds) the group at `recorded` with `held = true` and safe
 * `duplicateMeta` (group ULID, component method, MASKED matched reference) for the
 * `409 payment_reference_duplicate_suspected` conflict response. Both outcomes are
 * durable and committed — the difference is signalled to the controller, never by a
 * thrown exception (so idempotent replay caches the exact response).
 */
final readonly class PaymentRecordingResult
{
    /** @param array<string, mixed> $duplicateMeta */
    public function __construct(
        public PaymentRecordingGroup $group,
        public bool $held,
        public array $duplicateMeta,
        public int $balanceBeforeMinor,
        public int $pendingBeforeMinor,
        public int $availableAfterMinor,
    ) {}
}
