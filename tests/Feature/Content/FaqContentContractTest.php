<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'content', 'faq');

/*
 |==============================================================================
 | Phase UI-05 — compiled FAQ contract.
 |
 | UI-04 built `SvFaq`, the disclosure component. UI-05 supplies the data it renders, and the
 | compiler found a real defect while doing so (UI05-FAQ-001): the previous runtime parser accepted
 | a question only at `##`, and Merchant Administrator writes sixty of its questions at `###`. Those
 | sixty were silently dropped from every FAQ surface.
 |
 | These assertions therefore check completeness against the SOURCE (nothing may be dropped) as well
 | as fidelity (nothing may be reworded) and identity (ids must be stable and unique).
 */

it('compiles every question in every account FAQ, at any heading level', function (): void {
    $manifest = ui05Audit('faq-manifest');

    /** @var array<string, int> $counts */
    $counts = (array) $manifest['counts_by_account'];
    expect(array_keys($counts))->toHaveCount(8);

    $total = 0;
    foreach (UI05_ACCOUNTS as $account) {
        $source = (string) file_get_contents(base_path("docs/support/faq/{$account}_faq.md"));

        // The source's own definition of a question: a heading whose text starts with `N.M`.
        $expected = preg_match_all('/^#{1,6}\s+\d+\.\d+\s+\S/m', $source);

        expect($counts[$account])->toBe($expected, "{$account}: compiled {$counts[$account]} of {$expected} questions.");
        $total += $expected;
    }

    expect($manifest['total_items'])->toBe($total);
});

it('proves the sixty dropped Merchant Administrator questions are now compiled (UI05-FAQ-001)', function (): void {
    $source = (string) file_get_contents(base_path('docs/support/faq/merchant_administrator_faq.md'));

    $levelTwo = preg_match_all('/^##\s+\d+\.\d+\s+\S/m', $source);
    $levelThree = preg_match_all('/^###\s+\d+\.\d+\s+\S/m', $source);

    expect($levelTwo)->toBe(136);
    expect($levelThree)->toBe(60, 'The source no longer carries the level-three questions this defect was about.');

    $manifest = ui05Audit('faq-manifest');
    /** @var array<string, int> $counts */
    $counts = (array) $manifest['counts_by_account'];

    expect($counts['merchant_administrator'])->toBe($levelTwo + $levelThree);
});

it('gives every question a stable, unique, source-derived identifier', function (): void {
    $manifest = ui05Audit('faq-manifest');

    /** @var list<array<string, mixed>> $items */
    $items = $manifest['items'];
    $byAccount = [];

    foreach ($items as $item) {
        $account = (string) $item['account_key'];
        $byAccount[$account][] = $item;

        expect($item['id'])->toStartWith('faq-');
        // The id embeds the document's own numbering, so inserting a question elsewhere in the file
        // cannot renumber this one — which an array index would.
        expect($item['id'])->toContain(str_replace('.', '-', (string) $item['number']));
        expect($item['id'])->toMatch('/^[a-z0-9-]+$/');
        expect($item['answer_bytes'])->toBeGreaterThan(0);
    }

    foreach ($byAccount as $account => $accountItems) {
        $ids = array_map(static fn (array $item): string => (string) $item['id'], $accountItems);
        $numbers = array_map(static fn (array $item): string => (string) $item['number'], $accountItems);

        expect(array_unique($ids))->toHaveCount(count($ids), "{$account} has duplicate FAQ ids.");
        expect(array_unique($numbers))->toHaveCount(count($numbers), "{$account} has duplicate FAQ numbers.");
    }
});

it('quotes every question verbatim, in source order, from that account\'s own file', function (): void {
    $manifest = ui05Audit('faq-manifest');

    /** @var list<array<string, mixed>> $items */
    $items = $manifest['items'];
    $sources = [];
    $cursors = [];

    foreach ($items as $item) {
        $account = (string) $item['account_key'];
        $expectedPath = "docs/support/faq/{$account}_faq.md";

        expect($item['source_path'])->toBe($expectedPath, 'An FAQ item points at another account\'s file.');

        $sources[$account] ??= (string) file_get_contents(base_path($expectedPath));
        $cursors[$account] ??= 0;

        $at = strpos($sources[$account], (string) $item['question'], $cursors[$account]);
        expect($at)->not->toBeFalse("{$account}: question is not present verbatim: {$item['question']}");
        $cursors[$account] = (int) $at;

        /** @var list<int> $lines */
        $lines = $item['source_lines'];
        expect($lines[1])->toBeGreaterThanOrEqual($lines[0]);
    }
});

it('records the category divider each question sits under', function (): void {
    $manifest = ui05Audit('faq-manifest');

    /** @var list<array<string, mixed>> $items */
    $items = $manifest['items'];
    $categorised = array_filter($items, static fn (array $item): bool => $item['category'] !== null);

    // Every source document opens with `# 1. About Servana`, so the overwhelming majority of
    // questions sit under a divider. A collapse to zero would mean the divider rule stopped firing.
    expect(count($categorised))->toBeGreaterThan((int) (count($items) * 0.9));
});

it('states the parser rule that closed UI05-FAQ-001', function (): void {
    $manifest = ui05Audit('faq-manifest');

    expect($manifest['parser_rule'])->toContain('Heading LEVEL is not part of the rule');
    expect($manifest['parser_rule'])->toContain('UI05-FAQ-001');
});
