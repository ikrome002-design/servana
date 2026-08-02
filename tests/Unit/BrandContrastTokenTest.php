<?php

declare(strict_types=1);

uses()->group('brand', 'a11y');

/*
 | Brand contrast tokens (Plan §8 ADR-009; §79 R7). The approved foreground/
 | background token pairs must meet WCAG 2.1 AA (≥ 4.5:1 for normal text), so any
 | drift to a failing value fails this test. This also pins the recorded decision:
 | WHITE on the Savannah-Orange CTA fails AA, which is why dark brand text (Brand
 | Deep) is used on the CTA.
 |
 | Phase UI-04 moved every raw colour into one token authority
 | (`resources/spa/src/design-system/tokens.json`), from which
 | `resources/spa/src/styles/generated/tokens.css` is generated. `style.css` — where this test
 | used to read — now declares no colour of its own and merely imports that file, so the hex
 | values are read from the generated authority instead. The legacy `--color-*` names survive
 | there as ALIASES onto the semantic `--sv-color-*` scale, so the alias is resolved exactly as
 | a browser resolves it and the assertion lands on the value that actually paints. Every
 | threshold below is unchanged; only the location of the truth moved.
 */

/** Relative luminance of an #rrggbb colour (WCAG 2.1). */
function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channel = static function (int $v): float {
        $c = $v / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    $r = $channel((int) hexdec(substr($hex, 0, 2)));
    $g = $channel((int) hexdec(substr($hex, 2, 2)));
    $b = $channel((int) hexdec(substr($hex, 4, 2)));

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/** WCAG contrast ratio between two #rrggbb colours. */
function contrastRatio(string $a, string $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);
    [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

    return ($hi + 0.05) / ($lo + 0.05);
}

/** Read one custom property's declared value out of a CSS block. */
function brandTokenDeclaration(string $block, string $token): string
{
    expect(preg_match('/--'.preg_quote($token, '/').':\s*([^;]+);/', $block, $mm))->toBe(1);

    return trim($mm[1]);
}

/** Read a `--color-<name>` token from the generated light-theme :root block, resolving one alias hop. */
function brandToken(string $name): string
{
    $css = (string) file_get_contents(base_path('resources/spa/src/styles/generated/tokens.css'));
    // Match within the first :root { ... } (light theme) only; `:root.dark` follows it.
    $root = preg_match('/:root\s*\{(.*?)\}/s', $css, $m) ? $m[1] : $css;

    $value = brandTokenDeclaration($root, 'color-'.$name);

    // The legacy names are aliases onto the semantic scale: `--color-x: var(--sv-color-y)`.
    // Resolving the hop is what keeps this test asserting on the colour that actually paints.
    if (preg_match('/^var\(\s*--([a-z0-9-]+)\s*\)$/i', $value, $ref) === 1) {
        $value = brandTokenDeclaration($root, $ref[1]);
    }

    expect($value)->toMatch('/^#[0-9a-fA-F]{6}$/');

    return strtolower($value);
}

it('passes AA for dark brand text on the Savannah-Orange CTA', function (): void {
    $ratio = contrastRatio(brandToken('brand-deep'), brandToken('primary'));

    expect(round($ratio, 2))->toBeGreaterThanOrEqual(4.5);
});

it('records WHY white is NOT used on the CTA (white-on-orange fails AA)', function (): void {
    // The rejected combination — documented in ADR-009. White on the orange CTA
    // is below AA for normal text, which is the reason the CTA uses brand-deep.
    expect(contrastRatio('#ffffff', brandToken('primary')))->toBeLessThan(4.5);
});

it('passes AA for white text on the destructive (error) button', function (): void {
    expect(contrastRatio('#ffffff', brandToken('error')))->toBeGreaterThanOrEqual(4.5);
});

it('passes AA for the teal link/accent on a white surface', function (): void {
    expect(contrastRatio(brandToken('accent'), brandToken('surface')))->toBeGreaterThanOrEqual(4.5);
});

it('passes AA for body text on the app background', function (): void {
    expect(contrastRatio(brandToken('text'), brandToken('bg')))->toBeGreaterThanOrEqual(4.5);
});
