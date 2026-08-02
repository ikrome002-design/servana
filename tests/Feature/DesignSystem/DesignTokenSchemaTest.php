<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts');

/*
 |==============================================================================
 | Phase UI-04 — design-token schema contract (ADR-021; UI/UX plan §9.2, §9.6, §13.2).
 |
 | `resources/spa/src/design-system/tokens.json` is the SINGLE authority for Servana's brand,
 | semantic and component tokens. Before UI-04 the brand values lived inline in `style.css` and
 | were duplicated in `tailwind.config.ts`, so a change had two places to disagree.
 |
 | This suite proves the AUTHORITY is well formed. Generation parity is
 | `DesignTokenGenerationTest`, raw-value leakage is `DesignTokenSourceGuardTest`, and the
 | contrast contract is `DesignTokenContrastTest`.
 */

/*
 | `ui04Tokens()` is the shared reader for the token authority and lives in `tests/Pest.php`.
 | A Pest file-scope `function` is a GLOBAL, but it only exists once the file declaring it has
 | been loaded — under `--parallel` each ParaTest worker loads only its own slice, so a helper
 | declared here and consumed by a sibling spec resolves in serial and fatals in parallel
 | depending on how the files were distributed. Shared helpers therefore belong in the bootstrap.
 */

/**
 * Every semantic colour family UI/UX plan §10.3 requires. A component may only consume these
 * names; adding a colour means adding it here first, which is what stops per-component palettes.
 *
 * @var list<string>
 */
const UI04_REQUIRED_SEMANTIC_TOKENS = [
    'color-brand-primary', 'color-brand-primary-hover', 'color-brand-secondary', 'color-accent',
    'color-surface-page', 'color-surface-raised', 'color-surface-subtle', 'color-overlay-scrim',
    'color-text-primary', 'color-text-secondary', 'color-text-muted', 'color-text-inverse',
    'color-border-default', 'color-border-strong', 'color-focus-ring',
    'color-link', 'color-link-hover',
    'color-status-success-fg', 'color-status-success-bg', 'color-status-success-border',
    'color-status-warning-fg', 'color-status-warning-bg', 'color-status-warning-border',
    'color-status-error-fg', 'color-status-error-bg', 'color-status-error-border',
    'color-status-info-fg', 'color-status-info-bg', 'color-status-info-border',
    'color-disabled-fg', 'color-disabled-bg', 'color-disabled-border',
    'color-selected-fg', 'color-selected-bg', 'color-selected-border',
    'color-table-header', 'color-table-row-hover',
    'color-nav-active-fg', 'color-nav-active-bg', 'color-nav-inactive-fg',
    'color-footer-surface', 'color-footer-text', 'color-footer-border',
];

/**
 * Every component-token GROUP UI/UX plan §10.4 requires.
 *
 * @var list<string>
 */
const UI04_REQUIRED_COMPONENT_GROUPS = [
    'spacing', 'gutter', 'radius', 'border-width', 'shadow', 'focus', 'motion', 'z-index',
    'layout', 'footer', 'control', 'table', 'safe-area',
];

/** The seventeen brand values UI/UX plan §9.2 names as the light-theme foundation. */
const UI04_REQUIRED_PALETTE_VALUES = [
    'savannah-orange' => '#F97316',
    'golden-sun' => '#FBBF24',
    'acacia-green' => '#3F7D20',
    'deep-earth-brown' => '#4A2208',
    'service-teal' => '#007C78',
    'warm-sand' => '#FFF3C4',
    'savannah-cream' => '#FFF8E7',
    'charcoal' => '#1F2933',
    'soft-gray' => '#F3F4F6',
    'app-background' => '#F9FAFB',
    'status-success' => '#2E7D32',
    'status-warning' => '#F59E0B',
    'status-error' => '#DC2626',
    'status-info' => '#0284C7',
    'neutral-text' => '#374151',
    'muted-text' => '#6B7280',
    'border' => '#E5E7EB',
];

it('carries every approved brand palette value verbatim', function (): void {
    $palette = [];
    foreach (ui04Tokens()['palette'] as $entry) {
        $palette[$entry['name']] = $entry['value'];
    }

    $problems = [];
    foreach (UI04_REQUIRED_PALETTE_VALUES as $name => $value) {
        if (! array_key_exists($name, $palette)) {
            $problems[] = "brand palette entry '{$name}' is missing";

            continue;
        }
        if ($palette[$name] !== $value) {
            $problems[] = "brand palette entry '{$name}' is {$palette[$name]}, expected {$value}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('declares every required semantic colour family', function (): void {
    $names = array_column(ui04Tokens()['semantic'], 'name');

    $missing = array_values(array_diff(UI04_REQUIRED_SEMANTIC_TOKENS, $names));

    expect($missing)->toBe([], 'missing semantic tokens: '.implode(', ', $missing));
});

it('gives every semantic token an explicit value in BOTH themes', function (): void {
    $problems = [];

    foreach (ui04Tokens()['semantic'] as $token) {
        foreach (['light', 'dark'] as $theme) {
            if (! isset($token[$theme]) || trim((string) $token[$theme]) === '') {
                $problems[] = "{$token['name']}: no {$theme} value";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('resolves every semantic token to a real palette entry unless explicitly raw', function (): void {
    $tokens = ui04Tokens();
    $palette = array_column($tokens['palette'], 'name');
    $problems = [];

    foreach ($tokens['semantic'] as $token) {
        if (($token['raw'] ?? false) === true) {
            continue; // alpha values only; no palette entry can express them
        }
        foreach (['light', 'dark'] as $theme) {
            if (! in_array($token[$theme], $palette, true)) {
                $problems[] = "{$token['name']} ({$theme}): '{$token[$theme]}' is not a palette entry";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('declares every required component-token group', function (): void {
    $groups = array_values(array_unique(array_column(ui04Tokens()['component'], 'group')));

    $missing = array_values(array_diff(UI04_REQUIRED_COMPONENT_GROUPS, $groups));

    expect($missing)->toBe([], 'missing component-token groups: '.implode(', ', $missing));
});

it('rejects a duplicate token name anywhere in the authority', function (): void {
    $tokens = ui04Tokens();
    $names = [];

    foreach ($tokens['palette'] as $entry) {
        $names[] = 'palette:'.$entry['name'];
    }
    foreach ($tokens['semantic'] as $entry) {
        $names[] = 'semantic:'.$entry['name'];
    }
    foreach ($tokens['component'] as $entry) {
        $names[] = 'component:'.$entry['name'];
    }
    foreach ([...$tokens['typography']['families'], ...$tokens['typography']['scale'], ...$tokens['typography']['weights']] as $entry) {
        $names[] = 'typography:'.$entry['name'];
    }

    $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'duplicate token names: '.implode(', ', $duplicates));
});

it('pins the binding viewport contract to the plan', function (): void {
    // UI/UX plan §13.2 and CLAUDE.md guardrail 1. These four numbers are the whole responsive
    // contract; if they drift, every media query and every Tailwind screen drifts with them.
    expect(ui04Tokens()['breakpoints'])->toMatchArray([
        'mobile_max_px' => 767,
        'tablet_min_px' => 768,
        'tablet_max_px' => 1024,
        'desktop_min_px' => 1025,
    ]);
});

it('keeps Tailwind screens equal to the token breakpoint contract', function (): void {
    $config = (string) file_get_contents(base_path('tailwind.config.ts'));

    // Tailwind reads tokens.json directly, so the only way it can disagree is if someone
    // reintroduces a literal. Prove it interpolates and carries no hard-coded px screen.
    expect($config)->toContain('tokens.breakpoints');
    expect($config)->toContain('md: `${TABLET_MIN}px`');
    expect($config)->toContain('lg: `${DESKTOP_MIN}px`');
    expect($config)->not->toContain("md: '768px'");
    expect($config)->not->toContain("lg: '1025px'");
});

it('declares typography with Inter for UI and Manrope for display', function (): void {
    $families = [];
    foreach (ui04Tokens()['typography']['families'] as $family) {
        $families[$family['name']] = $family['value'];
    }

    expect($families)->toHaveKeys(['font-family-ui', 'font-family-display', 'font-family-numeric']);
    expect($families['font-family-ui'])->toStartWith('Inter');
    expect($families['font-family-display'])->toStartWith('Manrope');
});

it('gives every typography scale step a line height in rem', function (): void {
    $problems = [];

    foreach (ui04Tokens()['typography']['scale'] as $step) {
        if (! str_ends_with((string) $step['value'], 'rem')) {
            $problems[] = "{$step['name']}: font size must be scalable rem, found {$step['value']}";
        }
        if (! str_ends_with((string) $step['line_height'], 'rem')) {
            $problems[] = "{$step['name']}: line height must be rem, found {$step['line_height']}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('keeps the 44px minimum touch target', function (): void {
    // UI/UX plan §9.5. This value is never reduced, so it is asserted rather than merely present.
    $byName = [];
    foreach (ui04Tokens()['component'] as $token) {
        $byName[$token['name']] = $token['value'];
    }

    expect($byName['touch-target-min'])->toBe('44px');
});

it('defines a footer height for every breakpoint range plus the zoom fallback', function (): void {
    // ADR-024: ONE token drives both the footer's block size and the space the page reserves.
    $byName = [];
    foreach (ui04Tokens()['component'] as $token) {
        $byName[$token['name']] = $token['value'];
    }

    expect($byName)->toHaveKeys([
        'footer-height-mobile',
        'footer-height-tablet',
        'footer-height-desktop',
        'footer-height-zoom-fallback',
    ]);
});

it('resolves every legacy alias to a token that exists', function (): void {
    $tokens = ui04Tokens();
    $known = array_merge(
        array_column($tokens['semantic'], 'name'),
        array_column($tokens['component'], 'name'),
    );

    $problems = [];
    foreach ($tokens['legacy_aliases']['map'] as $alias => $target) {
        if (! in_array($target, $known, true)) {
            $problems[] = "{$alias} -> {$target} (no such token)";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});
