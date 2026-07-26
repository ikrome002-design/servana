<?php

declare(strict_types=1);

use App\Domain\Search\Enums\SearchDocumentType;
use App\Domain\Search\Enums\SearchSort;
use App\Domain\Search\Services\SearchQueryParser;
use App\Domain\Search\Support\SearchLikeTerm;
use App\Domain\Search\Support\SearchPhoneCandidate;

uses()->group('search', 'phase22', 'unit');

/*
 |==============================================================================
 | SearchQueryParser — the first control on the query string.
 |==============================================================================
 */

it('trims, collapses whitespace and strips control characters', function (string $raw, ?string $expected): void {
    expect((new SearchQueryParser)->parse($raw))->toBe($expected);
})->with([
    'plain' => ['Amina', 'Amina'],
    'padded' => ['   Amina   ', 'Amina'],
    'internal runs collapse' => ["Amina\t\t  Wanjiku", 'Amina Wanjiku'],
    'newlines collapse' => ["Amina\nWanjiku", 'Amina Wanjiku'],
    'nul stripped' => ["Am\x00ina", 'Am ina'],
    'escape stripped' => ["Amina\x1b[31m", 'Amina [31m'],
    'empty is refused' => ['', null],
    'whitespace only is refused' => ['     ', null],
    'control only is refused' => ["\x00\x01", null],
    'single character is refused' => ['a', null],
    'two characters are accepted' => ['ab', 'ab'],
]);

it('refuses a term over the maximum length', function (): void {
    $parser = new SearchQueryParser;

    expect($parser->parse(str_repeat('a', SearchQueryParser::MAX_LENGTH)))->not->toBeNull()
        ->and($parser->parse(str_repeat('a', SearchQueryParser::MAX_LENGTH + 1)))->toBeNull();
});

it('refuses a single character, because a one-letter prefix over a branch is enumeration', function (): void {
    expect(SearchQueryParser::MIN_LENGTH)->toBe(2);
});

/*
 |==============================================================================
 | SearchPhoneCandidate — only a COMPLETE number is phone-like.
 |==============================================================================
 */

it('recognises a complete phone number', function (string $term): void {
    expect(SearchPhoneCandidate::isPhoneLike($term))->toBeTrue();
})->with([
    'e164' => '+254712345678',
    'e164 spaced' => '+254 712 345 678',
    'e164 no plus' => '254712345678',
    'kenyan local 07' => '0712345678',
    'kenyan local 01' => '0112345678',
    'national significant 7' => '712345678',
    'national significant 1' => '112345678',
    'dashed' => '0712-345-678',
    'bracketed' => '(0712) 345678',
    'international other country' => '+2348012345678',
]);

it('refuses anything that is not a complete phone number', function (string $term): void {
    expect(SearchPhoneCandidate::isPhoneLike($term))->toBeFalse();
})->with([
    // Partial fragments would be a digit-by-digit confirmation oracle (ADR-010).
    'last four' => '5678',
    'six digits' => '071234',
    'seven digits' => '2345678',
    'eight digits' => '12345678',
    'nine digits wrong prefix' => '312345678',
    'ten digits wrong prefix' => '0312345678',
    // Legitimate business numbers must reach the text path.
    'receipt number' => '1024',
    'invoice number' => 'INV-000123',
    // Names, even with digits.
    'name' => 'Amina',
    'name with digits' => 'Amina 0712345678',
    'empty' => '',
    'plus only' => '+',
    'plus too short' => '+254712',
    'plus too long' => '+2547123456789012345',
]);

/*
 |==============================================================================
 | SearchLikeTerm — a term can only ever match itself.
 |==============================================================================
 */

it('escapes LIKE metacharacters so a term cannot widen its own pattern', function (): void {
    expect(SearchLikeTerm::escape('100%'))->toBe('100\\%')
        ->and(SearchLikeTerm::escape('a_b'))->toBe('a\\_b')
        ->and(SearchLikeTerm::escape('back\\slash'))->toBe('back\\\\slash')
        ->and(SearchLikeTerm::escape('%%'))->toBe('\\%\\%');
});

it('wraps an escaped term in a containment pattern', function (): void {
    expect(SearchLikeTerm::contains('Amina'))->toBe('%Amina%')
        ->and(SearchLikeTerm::contains('100%'))->toBe('%100\\%%');
});

it('leaves an ordinary term untouched', function (): void {
    expect(SearchLikeTerm::escape('Amina Wanjiku'))->toBe('Amina Wanjiku');
});

/*
 |==============================================================================
 | Enums are the allowlists.
 |==============================================================================
 */

it('marks every type except served_client as indexed', function (): void {
    foreach (SearchDocumentType::cases() as $type) {
        expect($type->isIndexed())->toBe($type !== SearchDocumentType::ServedClient);
    }
});

it('gives every type a sentence-case label', function (): void {
    foreach (SearchDocumentType::cases() as $type) {
        $label = $type->label();

        expect($label)->not->toBeEmpty()
            ->and($label)->toBe(ucfirst(strtolower($label)));
    }
});

it('allowlists exactly two sort tokens', function (): void {
    expect(SearchSort::values())->toBe(['relevance', 'recent']);
});

it('exposes no type whose value could collide with a filter or engine keyword', function (): void {
    foreach (SearchDocumentType::values() as $value) {
        expect($value)->toMatch('/^[a-z_]+$/');
    }
});
