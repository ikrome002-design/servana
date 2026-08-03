<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'content', 'legal');

/*
 |==============================================================================
 | Phase UI-05 — legal text preservation (UI/UX plan §17.2).
 |
 | Legal copy is the one category where "close enough" is a defect. The pipeline copies each
 | approved document into a generated TypeScript module as a single string literal, so the
 | interesting question is whether DECODING that literal yields the source file's exact bytes —
 | not whether the page renders.
 |
 | This test decodes the literal with PHP's own JSON decoder rather than trusting the generator,
 | so a bug in the emitter cannot mark its own homework.
 */

it('reproduces all twenty-four legal documents byte for byte', function (): void {
    $manifest = ui05Audit('legal-hash-manifest');

    /** @var list<array<string, mixed>> $documents */
    $documents = $manifest['documents'];
    expect($documents)->toHaveCount(24);
    expect($manifest['total_legal_documents'])->toBe(24);

    foreach ($documents as $document) {
        $sourcePath = (string) $document['source_path'];
        $modulePath = (string) $document['generated_module'];

        $source = (string) file_get_contents(base_path($sourcePath));
        $generated = ui05DecodeGeneratedMarkdown($modulePath);

        expect($generated)->toBe($source, "{$modulePath} no longer reproduces {$sourcePath}.");
        expect(hash('sha256', $source))->toBe($document['source_sha256']);
        expect(strlen($source))->toBe($document['source_bytes']);
        expect($document['verbatim'])->toBeTrue();

        // The module on disk must also be the module the manifest hashed.
        expect(hash('sha256', (string) file_get_contents(base_path($modulePath))))
            ->toBe($document['generated_sha256'], "{$modulePath} is stale.");
    }
});

it('covers every account and every legal category exactly once', function (): void {
    $manifest = ui05Audit('legal-hash-manifest');

    /** @var list<array<string, mixed>> $documents */
    $documents = $manifest['documents'];
    $pairs = array_map(
        static fn (array $document): string => "{$document['account_key']}:{$document['category']}",
        $documents,
    );

    foreach (UI05_ACCOUNTS as $account) {
        foreach (UI05_LEGAL_CATEGORIES as $category) {
            expect($pairs)->toContain("{$account}:{$category}");
        }
    }
    expect(array_unique($pairs))->toHaveCount(24);
});

it('never merges, shortens or reorders a legal document', function (): void {
    $manifest = ui05Audit('legal-hash-manifest');

    /** @var list<array<string, mixed>> $documents */
    $documents = $manifest['documents'];
    foreach ($documents as $document) {
        $source = (string) file_get_contents(base_path((string) $document['source_path']));
        $generated = ui05DecodeGeneratedMarkdown((string) $document['generated_module']);

        // Structure-preserving comparisons, so a failure names WHAT changed rather than only that
        // the bytes differ.
        expect(substr_count($generated, "\n"))->toBe(substr_count($source, "\n"), 'Line count changed.');
        expect(preg_match_all('/^#{1,6}\s+/m', $generated))
            ->toBe(preg_match_all('/^#{1,6}\s+/m', $source), 'Heading count changed.');
        expect(preg_match_all('/^\s*[-*]\s+/m', $generated))
            ->toBe(preg_match_all('/^\s*[-*]\s+/m', $source), 'List-item count changed.');
        expect(preg_match_all('#\[[^\]]*\]\([^)]+\)#', $generated))
            ->toBe(preg_match_all('#\[[^\]]*\]\([^)]+\)#', $source), 'Link count changed.');

        // One role's document may never contain another role's account name in its own path.
        foreach (UI05_ACCOUNTS as $other) {
            if ($other !== $document['account_key']) {
                expect((string) $document['source_path'])->not->toContain($other);
            }
        }
    }
});

it('leaves every legal source file untracked by the pipeline as writable input only', function (): void {
    // The generator must not rewrite its own inputs. Comparing the working tree against HEAD is the
    // repository-level statement of that: `git status` must report no modification under docs/legal.
    $output = [];
    $status = 0;
    exec('git -C '.escapeshellarg(base_path()).' status --porcelain -- docs/legal', $output, $status);

    expect($status)->toBe(0, 'git status failed.');
    expect($output)->toBe([], 'The pipeline modified approved legal source files: '.implode(', ', $output));
});
