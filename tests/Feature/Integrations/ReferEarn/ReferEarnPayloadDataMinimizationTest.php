<?php

declare(strict_types=1);

use App\Domain\Integrations\ReferEarn\Enums\ReOutboundEventType;
use App\Domain\Integrations\ReferEarn\Support\MerchantEventPayloadBuilder;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantProfile;
use Tests\Support\JsonSchemaAssert;

uses()->group('referearn', 'phase21ra', 'phase21ra-minimization');

/*
 | Cross-platform data minimization (Plan §9 rule 23, §58B.2, §74; ADR-013).
 |
 | Plan §58B.2 requires a forbidden-field test that "greps payload builders for banned sources".
 | This is that test, plus the structural guarantees that make it hold: every committed schema
 | forbids additional properties, every schema is reachable from the enum, and the builder reads no
 | PII-bearing model attribute at all.
 */

/**
 * The payload builder's CODE, with comments and docblocks stripped.
 *
 * Stripping matters: the class documents exactly which fields are forbidden, so a naive grep over
 * the raw file would match its own warning text and the guard would be permanently red for the
 * wrong reason. Tokenizing keeps the assertion about what the code actually reads.
 */
function payloadBuilderSource(): string
{
    $raw = (string) file_get_contents(app_path('Domain/Integrations/ReferEarn/Support/MerchantEventPayloadBuilder.php'));

    $code = '';

    foreach (token_get_all($raw) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('never reads a PII-bearing source in the payload builder', function (string $bannedSource): void {
    // Everything below is a real attribute or relation on a Servana model. If any of them appears in
    // the builder, a partner-facing payload is being built out of client, staff or contact data.
    expect(payloadBuilderSource())->not->toContain($bannedSource);
})->with([
    '->contact_email',
    '->contact_phone',
    '->phone_encrypted',
    '->email_encrypted',
    '->suspension_reason',
    '->raw_code_encrypted',
    '->code_normalized',
    '->address',
    'client', 'Client',
    'staff', 'Staff',
    'invoice', 'Invoice',
    'payment', 'Payment',
    'toArray()',
    'getAttributes()',
    'attributesToArray()',
]);

it('builds every payload from an explicit per-type allowlist, never from a spread', function (): void {
    $source = payloadBuilderSource();

    // A spread or array_merge of model data is exactly how a builder starts leaking silently.
    expect($source)->not->toContain('...$')
        ->not->toContain('array_merge($merchant')
        ->not->toContain('$merchant->toArray');
});

it('forbids additional properties in every committed schema', function (ReOutboundEventType $type): void {
    $schema = JsonSchemaAssert::load(base_path('docs/integrations/refer-earn/schemas/'.$type->schemaFile()));

    expect($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and($schema['type'])->toBe('object')
        // The envelope (Plan §58B.2) must be required by every event, not merely allowed.
        ->and($schema['required'])->toContain('product_code', 'environment', 'merchant_public_id', 'event_id', 'occurred_at', 'sequence_no', 'schema_version');
})->with(fn (): array => array_map(fn (ReOutboundEventType $t): array => [$t], ReOutboundEventType::cases()));

it('declares no forbidden property in any committed schema', function (): void {
    $forbidden = [
        'client_name', 'client_phone', 'customer_name', 'staff_name', 'staff_phone',
        'email', 'phone', 'msisdn', 'reason', 'suspension_reason', 'invoice_line',
        'invoice_line_description', 'payment_reference', 'provider_reference', 'referral_code',
        'raw_code', 'referrer', 'referrer_id', 'referrer_name', 'signature', 'idempotency_key',
        'sqlstate', 'constraint', 'stack_trace', 'internal_id', 'merchant_id',
    ];

    foreach (ReOutboundEventType::cases() as $type) {
        $schema = JsonSchemaAssert::load(base_path('docs/integrations/refer-earn/schemas/'.$type->schemaFile()));

        foreach (array_keys($schema['properties']) as $property) {
            expect($forbidden)->not->toContain($property, "{$type->value} declares forbidden property {$property}");
        }
    }
});

it('has a committed schema file for every event type and no orphan schema', function (): void {
    $directory = base_path('docs/integrations/refer-earn/schemas');

    $onDisk = collect(glob($directory.'/*.json') ?: [])
        ->map(fn (string $path): string => basename($path))
        // `_envelope.v1.json` documents the shared envelope and is referenced by prose, not emitted.
        ->reject(fn (string $name): bool => str_starts_with($name, '_'))
        ->sort()
        ->values()
        ->all();

    $expected = collect(ReOutboundEventType::cases())
        ->map(fn (ReOutboundEventType $t): string => $t->schemaFile())
        ->sort()
        ->values()
        ->all();

    expect($onDisk)->toBe($expected);
});

it('hashes merchant identity without exposing any identity value', function (): void {
    $source = (string) file_get_contents(app_path('Domain/Integrations/ReferEarn/Support/MerchantEventPayloadBuilder.php'));

    // The hash function must be the ONLY place identity values are read, and its result is a digest.
    expect($source)->toContain('CanonicalJson::sha256')
        ->and(MerchantEventPayloadBuilder::IDENTITY_FIELDS)->toBe([
            Merchant::class => ['name'],
            MerchantProfile::class => ['business_category', 'receipt_display_name'],
        ]);
});
