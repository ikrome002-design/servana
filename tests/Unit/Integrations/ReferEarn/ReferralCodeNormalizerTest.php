<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Support\LandingMetadataAllowlist;
use App\Domain\Integrations\ReferEarn\Support\ReferralCodeNormalizer;

uses()->group('referearn', 'phase21ra', 'phase21ra-unit');

/*
 | Referral-code normalization and landing-metadata filtering (Plan §58A.1, §13.17, §9 rule 23).
 |
 | Normalization decides whether a code is ever sent to a partner at all, so its edge cases are the
 | boundary between "the referrer gets their claim" and "Servana forwarded rubbish". `null` is the
 | invalid_format contract, enforced by the database CHECK.
 */

it('normalizes a well-formed code to its canonical uppercase form', function (string $submitted): void {
    expect(app(ReferralCodeNormalizer::class)->normalize($submitted))->toBe('SERVANA-X8T2K');
})->with([
    'already canonical' => ['SERVANA-X8T2K'],
    'lowercase' => ['servana-x8t2k'],
    'mixed case' => ['Servana-X8t2K'],
    'padded' => ["  SERVANA-X8T2K \t"],
    'quoted' => ['"SERVANA-X8T2K"'],
    'wrapped across a line' => ["SERVANA-\nX8T2K"],
    'internal space from a paste' => ['SERVANA- X8T2K'],
    'zero-width joiner from a rich-text paste' => ["SERVANA-X8T2\u{200B}K"],
    'non-breaking space' => ["SERVANA-X8T2\u{00A0}K"],
]);

it('rejects anything that is not a Servana referral code', function (?string $submitted): void {
    expect(app(ReferralCodeNormalizer::class)->normalize($submitted))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace only' => ['   '],
    'wrong prefix' => ['CITRUS-X8T2K'],
    'no prefix' => ['X8T2K'],
    'too short' => ['SERVANA-X8T2'],
    'too long (17 body characters; the pattern allows 5-16)' => ['SERVANA-'.str_repeat('A', 17)],
    'punctuation' => ['SERVANA-X8T2K!'],
    'sql-ish' => ["SERVANA-X8T2K' OR 1=1--"],
    'html' => ['<script>alert(1)</script>'],
    'oversized submission' => ['SERVANA-'.str_repeat('A', 200)],
]);

it('is deterministic — the same submission always normalizes identically', function (): void {
    $normalizer = app(ReferralCodeNormalizer::class);

    expect($normalizer->normalize(' servana-x8t2k '))
        ->toBe($normalizer->normalize('SERVANA-X8T2K'))
        ->toBe($normalizer->normalize("SERVANA-\nx8t2k"));
});

it('exposes a predicate that agrees with normalize()', function (): void {
    $normalizer = app(ReferralCodeNormalizer::class);

    expect($normalizer->isWellFormed('SERVANA-X8T2K'))->toBeTrue()
        ->and($normalizer->isWellFormed('nope'))->toBeFalse()
        ->and($normalizer->isWellFormed(null))->toBeFalse();
});

it('keeps only allowlisted landing-metadata keys', function (): void {
    $filtered = app(LandingMetadataAllowlist::class)->filter([
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'capture_variant' => 'b',
        'email' => 'someone@example.com',
        'ip' => '196.201.214.200',
        'user_agent' => 'Mozilla/5.0',
        'session_id' => 'abc',
        'referrer_name' => 'Jane',
    ]);

    expect(array_keys($filtered ?? []))->toBe(['capture_variant', 'utm_medium', 'utm_source']);
});

it('drops non-scalar and empty landing-metadata values', function (): void {
    $filtered = app(LandingMetadataAllowlist::class)->filter([
        'utm_source' => ['nested' => 'array'],
        'utm_medium' => '   ',
        'utm_campaign' => 'launch',
    ]);

    expect($filtered)->toBe(['utm_campaign' => 'launch']);
});

it('bounds landing-metadata values and strips control characters', function (): void {
    $filtered = app(LandingMetadataAllowlist::class)->filter([
        'utm_campaign' => str_repeat('x', 500),
        'utm_source' => "insta\ngram\x00",
    ]);

    expect(mb_strlen($filtered['utm_campaign']))->toBe(128)
        ->and($filtered['utm_source'])->toBe('instagram');
});

it('returns null rather than an empty bag when nothing survives', function (): void {
    expect(app(LandingMetadataAllowlist::class)->filter([]))->toBeNull()
        ->and(app(LandingMetadataAllowlist::class)->filter(['email' => 'a@b.c']))->toBeNull();
});

it('never allows a PII key into the allowlist itself', function (): void {
    $allowed = app(LandingMetadataAllowlist::class)->allowedKeys();

    foreach (['email', 'phone', 'name', 'ip', 'user_agent', 'cookie', 'session', 'msisdn', 'referrer_id'] as $forbidden) {
        expect($allowed)->not->toContain($forbidden);
    }

    expect($allowed)->toBe([
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'landing_path', 'referrer_host', 'capture_variant',
    ]);
});
