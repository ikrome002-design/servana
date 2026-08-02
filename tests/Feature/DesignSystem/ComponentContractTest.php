<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts');

/*
 |==============================================================================
 | Phase UI-04 — shared-component contract (UI/UX plan §10).
 |
 | `resources/spa/src/design-system/componentRegistry.ts` declares every shared component and what
 | it promises. This suite makes the registry a CHECKED contract rather than a document:
 |
 |  - every component the plan names is present;
 |  - every declared source and test file actually exists;
 |  - no component is a placeholder, an empty shell or a no-op wrapper;
 |  - no duplicate name, and no legacy duplicate of a canonical component;
 |  - every component carries typed props and, where it emits, typed emits.
 |
 | It reads the registry as text rather than executing TypeScript, so it stays offline and needs
 | no Node runtime — the PHP image deliberately has none.
 */

/** Every component name UI/UX plan §10 requires. */
const UI04_REQUIRED_COMPONENTS = [
    'SvButton', 'SvIconButton', 'SvLink', 'SvLogo', 'SvPageHeader', 'SvBreadcrumbs', 'SvCard',
    'SvMetricCard', 'SvStatusBadge', 'SvAlert', 'SvBanner', 'SvToast', 'SvDialog',
    'SvConfirmDialog', 'SvDrawer', 'SvPopover', 'SvMenu', 'SvTabs', 'SvAccordion', 'SvTooltip',
    'SvFormField', 'SvTextInput', 'SvTextArea', 'SvSelect', 'SvCombobox', 'SvCheckbox',
    'SvRadioGroup', 'SvDatePicker', 'SvMoneyInput', 'SvPhoneInput', 'SvFileUpload',
    'SvSearchInput', 'SvFilterBar', 'SvDataTable', 'SvResponsiveRecordList', 'SvPagination',
    'SvSkeleton', 'SvEmptyState', 'SvErrorState', 'SvOfflineState', 'SvPermissionState',
    'SvLockedState', 'SvTimeline', 'SvAuditEvent', 'SvMoney', 'SvDateTime', 'SvProfileControl',
    'SvAccountContextSwitcher', 'SvThemeToggle', 'SvNotificationsControl', 'SvLandingSection',
    'SvLegalDocument', 'SvFaq', 'SvFixedFooter',
];

/**
 * Parse the registry into name => contract fields.
 *
 * @return array<string, array{source: string, test: string, category: string}>
 */
function ui04Registry(): array
{
    $source = (string) file_get_contents(base_path('resources/spa/src/design-system/componentRegistry.ts'));

    preg_match_all(
        "/name:\s*'([^']+)',\s*\n\s*category:\s*'([^']+)',\s*\n\s*source:\s*(?:`\\\$\{UI\}([^`]+)`|'([^']+)'),\s*\n\s*test:\s*(\w+|'[^']+'),/",
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    // Test-path constants declared at the top of the registry.
    $constants = [];
    preg_match_all("/^const (\w+) = `\\\$\{UI\}([^`]+)`;$/m", $source, $constantMatches, PREG_SET_ORDER);
    foreach ($constantMatches as $constant) {
        $constants[$constant[1]] = 'resources/spa/src/components/ui/'.$constant[2];
    }

    $registry = [];
    foreach ($matches as $match) {
        $path = $match[3] !== '' ? 'resources/spa/src/components/ui/'.$match[3] : $match[4];
        $testRef = $match[5];
        $test = str_starts_with($testRef, "'")
            ? trim($testRef, "'")
            : ($constants[$testRef] ?? $testRef);

        $registry[$match[1]] = ['source' => $path, 'test' => $test, 'category' => $match[2]];
    }

    return $registry;
}

it('declares every shared component the plan requires', function (): void {
    $missing = array_values(array_diff(UI04_REQUIRED_COMPONENTS, array_keys(ui04Registry())));

    expect($missing)->toBe([], 'components missing from the registry: '.implode(', ', $missing));
});

it('parses the whole registry, so a silent parse failure cannot hide a gap', function (): void {
    // A regex that quietly matched nothing would make every other assertion here vacuous.
    expect(count(ui04Registry()))->toBe(count(UI04_REQUIRED_COMPONENTS));
});

it('points every registry entry at a source file that exists', function (): void {
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        if (! is_file(base_path($entry['source']))) {
            $problems[] = "{$name}: no such source {$entry['source']}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('points every registry entry at a test file that exists', function (): void {
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        if (! is_file(base_path($entry['test']))) {
            $problems[] = "{$name}: no such test {$entry['test']}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('exercises every component by name inside its declared test', function (): void {
    // A test file that never mentions the component proves nothing about it.
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        $test = (string) file_get_contents(base_path($entry['test']));
        if (! str_contains($test, $name)) {
            $problems[] = "{$name}: {$entry['test']} never references it";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('contains no placeholder, empty shell or no-op wrapper', function (): void {
    // UI/UX plan §13.1: a component that exists only to satisfy a filename count is a defect.
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        $body = (string) file_get_contents(base_path($entry['source']));

        if (preg_match('/\b(TODO|FIXME|placeholder component|not implemented)\b/i', $body) === 1) {
            $problems[] = "{$name}: carries a placeholder marker";
        }
        // A real component has a typed script block, a template, and enough substance to carry a
        // contract. The GENERIC form (`<script setup lang="ts" generic="TRow ...">`) is equally
        // typed — SvDataTable and SvResponsiveRecordList use it for the shared row type.
        if (preg_match('/<script setup lang="ts"(?:\s+generic="[^"]*")?>/', $body) !== 1) {
            $problems[] = "{$name}: no typed script setup block";
        }
        if (! str_contains($body, '<template>')) {
            $problems[] = "{$name}: no template";
        }
        if (strlen($body) < 400) {
            $problems[] = sprintf('%s: only %d bytes — too small to carry a real contract', $name, strlen($body));
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('gives every component typed props or a documented reason to have none', function (): void {
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        $body = (string) file_get_contents(base_path($entry['source']));

        // `defineProps<{...}>()` is the typed form; the untyped runtime form is not accepted.
        if (str_contains($body, 'defineProps(') && ! str_contains($body, 'defineProps<')) {
            $problems[] = "{$name}: uses untyped defineProps";
        }
        if (str_contains($body, 'defineEmits(') && ! str_contains($body, 'defineEmits<')) {
            $problems[] = "{$name}: uses untyped defineEmits";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('declares no duplicate component name', function (): void {
    $source = (string) file_get_contents(base_path('resources/spa/src/design-system/componentRegistry.ts'));
    preg_match_all("/^\s*name: '(\w+)',$/m", $source, $matches);

    $duplicates = array_keys(array_filter(array_count_values($matches[1]), static fn (int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'duplicate registry names: '.implode(', ', $duplicates));
});

it('leaves no legacy duplicate of a canonical component', function (): void {
    // UI-04 migrated SvInput -> SvTextInput, SvTextarea -> SvTextArea and SvModal -> SvDialog, and
    // removed the originals. Keeping both would be exactly the duplicate-alias situation §13.1
    // forbids, and a page could then use either.
    $retired = [
        'resources/spa/src/components/ui/SvInput.vue',
        'resources/spa/src/components/ui/SvModal.vue',
        'resources/spa/src/components/ui/SvTextarea.vue',
        // The UI-03 minimal switch control, superseded by SvAccountContextSwitcher.
        'resources/spa/src/components/ui/SvAccountSwitcher.vue',
    ];

    // CASE-EXACT existence. `file_exists()` is case-INSENSITIVE on Windows and macOS, so it
    // reports the retired `SvTextarea.vue` as present purely because the canonical
    // `SvTextArea.vue` exists — a false positive that would never reproduce on Linux CI.
    $survivors = array_values(array_filter($retired, static function (string $path): bool {
        $directory = dirname(base_path($path));
        $entries = is_dir($directory) ? (scandir($directory) ?: []) : [];

        return in_array(basename($path), $entries, true);
    }));

    expect($survivors)->toBe([], 'retired components still present: '.implode(', ', $survivors));

    // …and nothing still imports them.
    $references = [];
    foreach (sourceFilesUnder(base_path('resources/spa/src'), ['vue', 'ts']) as $path) {
        $body = (string) file_get_contents($path);
        foreach (['SvInput', 'SvModal', 'SvTextarea', 'SvAccountSwitcher'] as $name) {
            if (str_contains($body, "components/ui/{$name}.vue")) {
                $references[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1)).' -> '.$name;
            }
        }
    }

    expect($references)->toBe([], implode("\n", $references));
});

it('gives every component a category from the closed vocabulary', function (): void {
    $allowed = ['primitive', 'feedback', 'overlay', 'form', 'data', 'shell', 'content'];
    $problems = [];

    foreach (ui04Registry() as $name => $entry) {
        if (! in_array($entry['category'], $allowed, true)) {
            $problems[] = "{$name}: unknown category '{$entry['category']}'";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('covers every category', function (): void {
    $categories = array_values(array_unique(array_column(ui04Registry(), 'category')));

    expect(count($categories))->toBe(7);
});
