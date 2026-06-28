<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Support\ClientContactIndex;
use App\Domain\Clients\Support\PhoneNumberNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('clients', 'security');

/*
 | Client contact protection (Plan §35; guardrail §6.4; Phase 15A). The novel
 | security mechanism: phone is encrypted at rest, displayed masked, and
 | searchable / duplicate-checked through a keyed HMAC blind index — never a
 | plaintext index, never reversible deterministic ciphertext, never exposed.
 */

it('normalizes equivalent Kenyan phone forms to one canonical number', function (): void {
    foreach (['0712345678', '+254712345678', '254712345678', '712345678'] as $form) {
        expect(PhoneNumberNormalizer::normalize($form))->toBe('+254712345678');
    }
});

it('produces a deterministic blind index that matches across equivalent forms', function (): void {
    $a = ClientContactIndex::for('0712345678');
    $b = ClientContactIndex::for('+254712345678');

    expect($a)->toBe($b)
        ->and($a)->toMatch('/^[0-9a-f]{64}$/')           // HMAC-SHA256 hex
        ->and($a)->not->toContain('712345678');          // one-way: no plaintext leakage
});

it('stores the phone as ciphertext, never plaintext', function (): void {
    $client = Client::factory()->withPhone('0712345678')->create();

    $raw = DB::table('clients')->where('id', $client->id)->value('phone_encrypted');

    expect($raw)->not->toBe('+254712345678')
        ->and($client->fresh()->phone_encrypted)->toBe('+254712345678'); // decrypts transparently
});

it('never serializes the blind index or ciphertext', function (): void {
    $array = Client::factory()->create()->toArray();

    expect($array)->not->toHaveKey('phone_index')
        ->and($array)->not->toHaveKey('phone_encrypted')
        ->and($array)->not->toHaveKey('email_encrypted');
});

it('masks the phone to the last four digits only', function (): void {
    $client = Client::factory()->withPhone('0712345678')->create();

    expect($client->maskedPhone())->toBe('••• ••• 5678')
        ->and($client->phone_last_four)->toBe('5678');
});

it('prevents a duplicate active client per branch + normalized phone', function (): void {
    $first = Client::factory()->withPhone('0712345678')->create();

    expect(fn () => Client::factory()
        ->withPhone('+254712345678') // same normalized number, same branch
        ->create([
            'merchant_id' => $first->merchant_id,
            'branch_id' => $first->branch_id,
        ]))
        ->toThrow(Illuminate\Database\QueryException::class); // partial-unique index violation
});

it('allows the same phone in a different branch', function (): void {
    $first = Client::factory()->withPhone('0712345678')->create();
    $second = Client::factory()->withPhone('0712345678')->create(); // independent branch/merchant

    expect($second->branch_id)->not->toBe($first->branch_id)
        ->and($second->phone_index)->toBe($first->phone_index); // same index, different branch bucket
});
