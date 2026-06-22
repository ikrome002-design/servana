<?php

declare(strict_types=1);

use App\Domain\Idempotency\Support\CanonicalRequestHasher;

uses()->group('idempotency');

/*
 | Canonical request hash (Plan §24.4 step 2; Phase R4). JSON key ordering is
 | irrelevant; method/route/path/body/content-type changes are material.
 */

beforeEach(function (): void {
    $this->hasher = new CanonicalRequestHasher;
});

it('hashes equivalent JSON identically regardless of key order', function (): void {
    $a = $this->hasher->hash('POST', 'r', [], 'application/json', ['b' => 2, 'a' => 1, 'nested' => ['y' => 1, 'x' => 2]]);
    $b = $this->hasher->hash('POST', 'r', [], 'application/json', ['a' => 1, 'nested' => ['x' => 2, 'y' => 1], 'b' => 2]);

    expect($a)->toBe($b)->and($a)->toHaveLength(64);
});

it('hashes differently for a different method', function (): void {
    expect($this->hasher->hash('POST', 'r', [], 'application/json', ['a' => 1]))
        ->not->toBe($this->hasher->hash('PUT', 'r', [], 'application/json', ['a' => 1]));
});

it('hashes differently for a different route', function (): void {
    expect($this->hasher->hash('POST', 'r1', [], 'application/json', ['a' => 1]))
        ->not->toBe($this->hasher->hash('POST', 'r2', [], 'application/json', ['a' => 1]));
});

it('hashes differently for different path parameters', function (): void {
    expect($this->hasher->hash('POST', 'r', ['invoice' => 'AAA'], 'application/json', []))
        ->not->toBe($this->hasher->hash('POST', 'r', ['invoice' => 'BBB'], 'application/json', []));
});

it('hashes differently for a different body', function (): void {
    expect($this->hasher->hash('POST', 'r', [], 'application/json', ['amount' => 100]))
        ->not->toBe($this->hasher->hash('POST', 'r', [], 'application/json', ['amount' => 200]));
});

it('hashes differently for a different content type', function (): void {
    expect($this->hasher->hash('POST', 'r', [], 'application/json', ['a' => 1]))
        ->not->toBe($this->hasher->hash('POST', 'r', [], 'text/plain', ['a' => 1]));
});

it('ignores content-type parameters like charset', function (): void {
    expect($this->hasher->hash('POST', 'r', [], 'application/json', ['a' => 1]))
        ->toBe($this->hasher->hash('POST', 'r', [], 'application/json; charset=utf-8', ['a' => 1]));
});

it('preserves list ordering as significant', function (): void {
    expect($this->hasher->hash('POST', 'r', [], 'application/json', ['items' => [1, 2, 3]]))
        ->not->toBe($this->hasher->hash('POST', 'r', [], 'application/json', ['items' => [3, 2, 1]]));
});
