<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts', 'source-guard');

/*
 |==============================================================================
 | Phase UI-04 — raw-value source guard (UI/UX plan §9.2, §13.1; ADR-021).
 |
 | Semantic tokens only work if components cannot bypass them. This guard scans PRODUCTION UI
 | source and fails on a raw colour, a JavaScript device detection, a disabled zoom, or a
 | hard-coded production host — the four ways a component quietly grows its own design system or
 | its own routing authority.
 |
 | It is deliberately narrow and evidence-driven:
 |  - it scans real production source, never docs, legal copy, fixtures or generated output;
 |  - it strips comments before matching, so a documented hex in a comment is not a violation
 |    while a hex in a class attribute is;
 |  - every rule has a NEGATIVE CONTROL below proving the matcher actually fires.
 */

/** The files a component author edits. Generated artifacts and the token authority are excluded. */
const UI04_TOKEN_AUTHORITY_PATHS = [
    'resources/spa/src/design-system/tokens.json',
    'resources/spa/src/design-system/tokens.generated.ts',
    'resources/spa/src/styles/generated/tokens.css',
];

/**
 * @return list<string> absolute paths of production UI source
 */
function ui04ProductionUiSource(): array
{
    $roots = [
        base_path('resources/spa/src/components'),
        base_path('resources/spa/src/layouts'),
        base_path('resources/spa/src/pages'),
        base_path('resources/spa/src/navigation'),
        base_path('resources/spa/src/router'),
        base_path('resources/spa/src/design-system'),
        base_path('resources/spa/src/styles'),
    ];

    $files = [];
    foreach ($roots as $root) {
        foreach (sourceFilesUnder($root, ['vue', 'ts', 'css']) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            if (in_array($relative, UI04_TOKEN_AUTHORITY_PATHS, true)) {
                continue;
            }
            // Specs assert on values deliberately; they are tests, not shipped UI.
            if (str_ends_with($path, '.spec.ts')) {
                continue;
            }
            $files[] = $path;
        }
    }
    $files[] = base_path('resources/spa/src/style.css');
    $files[] = base_path('tailwind.config.ts');

    sort($files);

    return $files;
}

/**
 * Remove comments so a documented value is not mistaken for a used one. Handles the three comment
 * forms that appear in this repository's `.vue`, `.ts` and `.css` files.
 */
function ui04StripComments(string $contents): string
{
    // LINE-PRESERVING: a multi-line comment becomes blanks, not nothing, so reported line numbers
    // still point at the real source.
    $blank = static fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

    // Block comments (/* … */), HTML comments (<!-- … -->) and Blade comments ({{-- … --}}).
    $stripped = (string) preg_replace_callback('#/\*.*?\*/#s', $blank, $contents);
    $stripped = (string) preg_replace_callback('#<!--.*?-->#s', $blank, $stripped);
    $stripped = (string) preg_replace_callback('#\{\{--.*?--\}\}#s', $blank, $stripped);

    // Line comments (// …) — but not the `//` inside a URL scheme.
    $lines = array_map(
        static fn (string $line): string => (string) preg_replace('#(?<!:)//.*$#', '', $line),
        explode("\n", $stripped),
    );

    return implode("\n", $lines);
}

/** @return list<string> violation descriptions */
function ui04ScanForRawHex(string $relative, string $body): array
{
    $violations = [];
    foreach (explode("\n", $body) as $number => $line) {
        if (preg_match_all('/#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?\b/', $line, $matches) === 0) {
            continue;
        }
        foreach ($matches[0] as $hex) {
            $violations[] = sprintf('%s:%d raw colour %s', $relative, $number + 1, $hex);
        }
    }

    return $violations;
}

it('finds no raw colour value in production UI source', function (): void {
    $violations = [];

    foreach (ui04ProductionUiSource() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $body = ui04StripComments((string) file_get_contents($path));
        $violations = array_merge($violations, ui04ScanForRawHex($relative, $body));
    }

    expect($violations)->toBe([], sprintf(
        "%d raw colour value(s) outside the token authority.\nUse a semantic token (resources/spa/src/design-system/tokens.json) and consume it through Tailwind or var(--sv-*):\n%s",
        count($violations),
        implode("\n", $violations),
    ));
});

it('finds no unauthorized arbitrary Tailwind colour literal', function (): void {
    // `bg-[#F97316]` / `text-[rgb(…)]` reintroduce a per-component palette through the back door.
    $violations = [];

    foreach (ui04ProductionUiSource() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $body = ui04StripComments((string) file_get_contents($path));

        if (preg_match_all('/\b(?:bg|text|border|ring|fill|stroke|from|via|to|outline|decoration|shadow)-\[(?:#|rgb|hsl)[^\]]*\]/', $body, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $violations[] = "{$relative}: arbitrary colour utility {$match}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('finds no JavaScript device or viewport detection in production UI source', function (): void {
    // CLAUDE.md guardrail 1 and UI/UX plan §13.1: responsive layout is CSS media queries only.
    // `matchMedia('(prefers-reduced-motion…)')` is an ACCESSIBILITY preference, not a device or
    // layout decision, so it is allowed; width/device/orientation/colour-scheme queries are not.
    $violations = [];

    $patterns = [
        '/navigator\s*\.\s*userAgent/' => 'user-agent detection',
        '/navigator\s*\.\s*platform/' => 'platform detection',
        '/navigator\s*\.\s*maxTouchPoints/' => 'touch-capability detection',
        '/window\s*\.\s*(?:innerWidth|outerWidth|screen\s*\.\s*width)/' => 'viewport-width measurement used to choose a layout',
        '/matchMedia\s*\(\s*[\'"`][^\'"`]*(?:max-width|min-width|orientation|pointer|hover|prefers-color-scheme)/' => 'JavaScript breakpoint / colour-scheme query',
    ];

    foreach (ui04ProductionUiSource() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        $body = ui04StripComments((string) file_get_contents($path));

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $body) === 1) {
                $violations[] = "{$relative}: {$label}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('never disables browser zoom in any application shell', function (): void {
    // CLAUDE.md guardrail 1. Both shells must stay byte-comparable on this point.
    foreach (['resources/spa/index.html', 'resources/views/spa.blade.php'] as $shell) {
        // Both shells CARRY A COMMENT naming the rule ("never disable zoom. No maximum-scale /
        // user-scalable"), so comments must be stripped or the guard fails on its own reminder.
        $body = (string) preg_replace(
            ['#\{\{--.*?--\}\}#s', '#<!--.*?-->#s'],
            '',
            (string) file_get_contents(base_path($shell)),
        );

        // `toContain` is variadic in Pest, so both zoom restrictions are asserted as needles and
        // the failure explanation is carried by the collected-problems assertion instead.
        $restrictions = array_values(array_filter(
            ['maximum-scale', 'user-scalable'],
            static fn (string $needle): bool => str_contains($body, $needle),
        ));

        expect($restrictions)->toBe([], "{$shell} restricts zoom: ".implode(', ', $restrictions));
        expect($body)->toContain('width=device-width, initial-scale=1');
    }
});

it('finds no hard-coded production account host outside the generated registry', function (): void {
    // UI-02 owns the host registry; a component that hard-codes `servana.ke` has invented a
    // second one. The generated registry and its own spec are the only legitimate carriers.
    $violations = [];

    foreach (ui04ProductionUiSource() as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
        if (str_contains($relative, 'accountHosts.generated.ts')) {
            continue;
        }
        $body = ui04StripComments((string) file_get_contents($path));

        if (preg_match('/[a-z0-9-]*\.?servana\.ke\b/i', $body) === 1) {
            $violations[] = "{$relative}: hard-coded production account host";
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('scans a real, non-empty set of production files', function (): void {
    // A guard that silently scans nothing gives false assurance. PH23-SCAN-001 is the precedent.
    $files = ui04ProductionUiSource();

    expect(count($files))->toBeGreaterThan(100, 'the production UI source scan collected suspiciously few files');
    expect(implode('|', $files))->toContain('components');
    expect(implode('|', $files))->toContain('layouts');
    expect(implode('|', $files))->toContain('pages');
});

/*
 |------------------------------------------------------------------------------
 | Negative controls — every matcher above must actually fire on a known violation.
 |------------------------------------------------------------------------------
 */

it('detects a raw hex when one is genuinely present (negative control)', function (): void {
    $violations = ui04ScanForRawHex('fake.vue', '<div class="border" style="color: #F97316">x</div>');

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('#F97316');
});

it('ignores a hex that appears only inside a comment (negative control)', function (): void {
    $body = ui04StripComments("/* brand primary is #F97316 */\n<div class=\"bg-sv-brand\" />");

    expect(ui04ScanForRawHex('fake.vue', $body))->toBe([]);
});

it('detects an arbitrary Tailwind colour literal when present (negative control)', function (): void {
    $body = ui04StripComments('<div class="bg-[#F97316] text-[rgb(0,0,0)]" />');

    expect(preg_match_all('/\b(?:bg|text|border|ring|fill|stroke|from|via|to|outline|decoration|shadow)-\[(?:#|rgb|hsl)[^\]]*\]/', $body))
        ->toBe(2);
});

it('detects JavaScript device detection when present (negative control)', function (): void {
    $body = ui04StripComments("const isMobile = window.innerWidth < 768;\nconst ua = navigator.userAgent;");

    expect(preg_match('/window\s*\.\s*(?:innerWidth|outerWidth|screen\s*\.\s*width)/', $body))->toBe(1);
    expect(preg_match('/navigator\s*\.\s*userAgent/', $body))->toBe(1);
});

it('allows the reduced-motion preference query (negative control)', function (): void {
    // Proving the device-detection matcher is not over-broad: an accessibility preference is not
    // a layout decision and must remain legal.
    $body = ui04StripComments("const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;");

    expect(preg_match('/matchMedia\s*\(\s*[\'"`][^\'"`]*(?:max-width|min-width|orientation|pointer|hover|prefers-color-scheme)/', $body))
        ->toBe(0);
});

it('detects a hard-coded production host when present (negative control)', function (): void {
    $body = ui04StripComments("const target = 'https://finance.servana.ke/dashboard';");

    expect(preg_match('/[a-z0-9-]*\.?servana\.ke\b/i', $body))->toBe(1);
});
