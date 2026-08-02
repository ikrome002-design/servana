<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'accessibility', 'contracts');

/*
 |==============================================================================
 | Phase UI-04 — design-token contrast contract (ADR-021 §6; UI/UX plan §12.4, §19).
 |
 | ADR-009 records that forced dark mode has already produced real contrast failures in this
 | codebase. "Both themes meet AA" is therefore COMPUTED here from the token authority, not
 | asserted by eye in a review. Every requirement in `tokens.json.contrast_requirements` is
 | evaluated in light AND dark.
 |
 | This is an offline maths test: it reads one JSON file and computes WCAG 2.1 relative
 | luminance. It never renders a browser — that is `ui-04-accessibility.spec.ts`.
 */

/** WCAG 2.1 relative-luminance channel transform. */
function ui04Channel(float $value): float
{
    $c = $value / 255;

    return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
}

function ui04RelativeLuminance(string $hex): float
{
    $r = (float) hexdec(substr($hex, 1, 2));
    $g = (float) hexdec(substr($hex, 3, 2));
    $b = (float) hexdec(substr($hex, 5, 2));

    return 0.2126 * ui04Channel($r) + 0.7152 * ui04Channel($g) + 0.0722 * ui04Channel($b);
}

function ui04ContrastRatio(string $foreground, string $background): float
{
    $a = ui04RelativeLuminance($foreground);
    $b = ui04RelativeLuminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

/** Resolve a semantic token to its hex value in one theme, or null when it is a raw alpha value. */
function ui04ResolveColor(string $tokenName, string $theme): ?string
{
    $tokens = ui04Tokens();
    $palette = [];
    foreach ($tokens['palette'] as $entry) {
        $palette[$entry['name']] = $entry['value'];
    }

    foreach ($tokens['semantic'] as $token) {
        if ($token['name'] !== $tokenName) {
            continue;
        }
        if (($token['raw'] ?? false) === true) {
            return null;
        }

        return $palette[$token[$theme]] ?? null;
    }

    return null;
}

it('meets every declared contrast requirement in BOTH themes', function (): void {
    $failures = [];

    foreach (ui04Tokens()['contrast_requirements'] as $requirement) {
        foreach (['light', 'dark'] as $theme) {
            $foreground = ui04ResolveColor($requirement['foreground'], $theme);
            $background = ui04ResolveColor($requirement['background'], $theme);

            expect($foreground)->not->toBeNull("{$requirement['id']} ({$theme}): foreground does not resolve to a hex colour");
            expect($background)->not->toBeNull("{$requirement['id']} ({$theme}): background does not resolve to a hex colour");

            $ratio = ui04ContrastRatio((string) $foreground, (string) $background);
            if ($ratio + 1e-9 < (float) $requirement['min_ratio']) {
                $failures[] = sprintf(
                    '%s (%s): %s on %s is %.2f:1, below the required %s:1',
                    $requirement['id'],
                    $theme,
                    $foreground,
                    $background,
                    $ratio,
                    $requirement['min_ratio'],
                );
            }
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('holds body text to AA on every surface it can land on, in both themes', function (): void {
    // The requirement list is data and could in principle lose a row. This test hard-codes the
    // combinations that must ALWAYS hold, so deleting a requirement cannot silently weaken the gate.
    $failures = [];

    foreach (['light', 'dark'] as $theme) {
        foreach (['color-surface-page', 'color-surface-raised', 'color-surface-subtle'] as $surface) {
            foreach (['color-text-primary', 'color-text-secondary', 'color-text-muted'] as $text) {
                $ratio = ui04ContrastRatio(
                    (string) ui04ResolveColor($text, $theme),
                    (string) ui04ResolveColor($surface, $theme),
                );
                if ($ratio + 1e-9 < 4.5) {
                    $failures[] = sprintf('%s on %s (%s) is %.2f:1', $text, $surface, $theme, $ratio);
                }
            }
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('keeps the focus ring perceivable on every surface, in both themes', function (): void {
    // WCAG 1.4.11 non-text contrast. A focus ring that disappears is the single most damaging
    // accessibility regression a token change can cause, so it is gated independently.
    $failures = [];

    foreach (['light', 'dark'] as $theme) {
        $ring = (string) ui04ResolveColor('color-focus-ring', $theme);
        foreach (['color-surface-page', 'color-surface-raised', 'color-surface-subtle', 'color-table-header'] as $surface) {
            $ratio = ui04ContrastRatio($ring, (string) ui04ResolveColor($surface, $theme));
            if ($ratio + 1e-9 < 3.0) {
                $failures[] = sprintf('focus ring on %s (%s) is %.2f:1', $surface, $theme, $ratio);
            }
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('keeps every status colour distinguishable from its own background, in both themes', function (): void {
    // Status must never be conveyed by colour alone (UI/UX plan §9.4), but when it IS conveyed by
    // colour the text on that tint still has to be readable.
    $failures = [];

    foreach (['success', 'warning', 'error', 'info'] as $status) {
        foreach (['light', 'dark'] as $theme) {
            $ratio = ui04ContrastRatio(
                (string) ui04ResolveColor("color-status-{$status}-fg", $theme),
                (string) ui04ResolveColor("color-status-{$status}-bg", $theme),
            );
            if ($ratio + 1e-9 < 4.5) {
                $failures[] = sprintf('%s foreground on its background (%s) is %.2f:1', $status, $theme, $ratio);
            }
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('keeps the fixed footer legible in both themes', function (): void {
    // ADR-021 §6 names the fixed footer explicitly, because it renders on every page of every host.
    foreach (['light', 'dark'] as $theme) {
        $ratio = ui04ContrastRatio(
            (string) ui04ResolveColor('color-footer-text', $theme),
            (string) ui04ResolveColor('color-footer-surface', $theme),
        );
        expect($ratio)->toBeGreaterThanOrEqual(4.5, "footer text ({$theme}) is {$ratio}:1");
    }
});

it('computes a known ratio correctly, so the maths itself is proven', function (): void {
    // Negative/positive control for the luminance implementation. Black on white is exactly 21:1.
    expect(round(ui04ContrastRatio('#000000', '#FFFFFF'), 2))->toBe(21.0);
    expect(round(ui04ContrastRatio('#FFFFFF', '#FFFFFF'), 2))->toBe(1.0);
    // A pair the audit would have to reject: Savannah Orange as text on white is below AA.
    expect(ui04ContrastRatio('#F97316', '#FFFFFF'))->toBeLessThan(3.0);
});
