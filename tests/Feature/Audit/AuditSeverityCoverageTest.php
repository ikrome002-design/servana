<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Support\AuditMutationCoverage;

uses()->group('audit', 'coverage');

/*
 | Phase 19 Increment 5 — every typed audit event carries a defined severity and a
 | read-segment domain (Plan §70; ADR-008). The severity()/domain() matches are
 | exhaustive by construction (no default arm), so a new case without a mapping is a
 | compile error; these assert the mapping is total, tiered, and self-consistent.
 */

it('maps every AuditEvent case to a valid severity without throwing', function (): void {
    foreach (AuditEvent::cases() as $event) {
        expect($event->severity())->toBeInstanceOf(AuditSeverity::class);
    }
});

it('maps every AuditEvent case to a read-segment domain', function (): void {
    foreach (AuditEvent::cases() as $event) {
        expect($event->domain())->toBeInstanceOf(AuditDomain::class);
    }
});

it('represents every severity tier across the current catalogue', function (): void {
    $tiers = array_unique(array_map(
        static fn (AuditEvent $e): string => $e->severity()->value,
        AuditEvent::cases(),
    ));

    foreach ([AuditSeverity::Info, AuditSeverity::Notice, AuditSeverity::Warning, AuditSeverity::High] as $tier) {
        expect($tiers)->toContain($tier->value);
    }
});

it('gives every route-referenced audit event a resolvable severity (registry ↔ enum)', function (): void {
    $byValue = [];
    foreach (AuditEvent::cases() as $event) {
        $byValue[$event->value] = $event;
    }

    foreach (AuditMutationCoverage::referencedEvents() as $action) {
        expect($byValue)->toHaveKey($action);
        expect($byValue[$action]->severity())->toBeInstanceOf(AuditSeverity::class);
    }
});

it('classifies each finance-domain audit event into the Finance read segment', function (): void {
    // Sanity: the registry's finance-surface events resolve to AuditDomain::Finance,
    // keeping Increment 3's domain segmentation and Increment 5's coverage consistent.
    $financeSamples = ['invoice.created', 'customer_payment.recorded', 'refund.finalized', 'finance_export.requested'];

    $byValue = [];
    foreach (AuditEvent::cases() as $event) {
        $byValue[$event->value] = $event;
    }

    foreach ($financeSamples as $action) {
        expect($byValue[$action]->domain())->toBe(AuditDomain::Finance);
    }
});
