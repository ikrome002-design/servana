import type { Config } from 'tailwindcss';
import tokens from './resources/spa/src/design-system/tokens.json';

// Tailwind is a CONSUMER of the design-token authority, never a second source of truth
// (ADR-021; UI/UX plan §9.2, §9.6, §13.2).
//
//  - Colours map onto the CSS custom properties emitted into
//    `resources/spa/src/styles/generated/tokens.css` by `node scripts/generate-design-tokens.mjs`,
//    so a brand change is applied in exactly one place and both themes follow automatically.
//  - Breakpoints are read from `tokens.json` itself, so the binding viewport contract
//    (mobile ≤767, tablet 768–1024, desktop ≥1025) cannot drift from the plan.
//
// This file contains no raw colour value; `DesignTokenSourceGuardTest` enforces that.

const { tablet_min_px: TABLET_MIN, desktop_min_px: DESKTOP_MIN } = tokens.breakpoints;

/** Shorthand for a token's CSS variable. */
const sv = (token: string): string => `var(--sv-${token})`;

export default {
  content: ['./resources/spa/index.html', './resources/spa/src/**/*.{vue,ts}'],
  // ADR-021: class strategy with light as the default. `prefers-color-scheme` is never consulted.
  darkMode: 'class',
  theme: {
    // Overriding `screens` (not extending) removes Tailwind's sm/xl defaults, so only the two
    // plan-mandated boundaries exist. `md` = tablet floor, `lg` = desktop floor.
    screens: {
      md: `${TABLET_MIN}px`,
      lg: `${DESKTOP_MIN}px`,
    },
    extend: {
      colors: {
        // --- canonical semantic names (new components use these) ---
        'sv-brand': {
          DEFAULT: sv('color-brand-primary'),
          hover: sv('color-brand-primary-hover'),
          secondary: sv('color-brand-secondary'),
        },
        'sv-accent': sv('color-accent'),
        'sv-surface': {
          page: sv('color-surface-page'),
          raised: sv('color-surface-raised'),
          subtle: sv('color-surface-subtle'),
          warm: sv('color-surface-warm'),
        },
        'sv-scrim': sv('color-overlay-scrim'),
        'sv-text': {
          DEFAULT: sv('color-text-primary'),
          secondary: sv('color-text-secondary'),
          muted: sv('color-text-muted'),
          inverse: sv('color-text-inverse'),
          'on-brand': sv('color-text-on-brand'),
          heading: sv('color-text-heading'),
        },
        'sv-border': {
          DEFAULT: sv('color-border-default'),
          strong: sv('color-border-strong'),
          input: sv('color-border-input'),
        },
        'sv-focus': sv('color-focus-ring'),
        'sv-link': {
          DEFAULT: sv('color-link'),
          hover: sv('color-link-hover'),
        },
        'sv-success': {
          fg: sv('color-status-success-fg'),
          bg: sv('color-status-success-bg'),
          border: sv('color-status-success-border'),
        },
        'sv-warning': {
          fg: sv('color-status-warning-fg'),
          bg: sv('color-status-warning-bg'),
          border: sv('color-status-warning-border'),
        },
        'sv-error': {
          fg: sv('color-status-error-fg'),
          bg: sv('color-status-error-bg'),
          border: sv('color-status-error-border'),
        },
        'sv-info': {
          fg: sv('color-status-info-fg'),
          bg: sv('color-status-info-bg'),
          border: sv('color-status-info-border'),
        },
        'sv-disabled': {
          fg: sv('color-disabled-fg'),
          bg: sv('color-disabled-bg'),
          border: sv('color-disabled-border'),
        },
        'sv-selected': {
          fg: sv('color-selected-fg'),
          bg: sv('color-selected-bg'),
          border: sv('color-selected-border'),
        },
        'sv-table': {
          header: sv('color-table-header'),
          hover: sv('color-table-row-hover'),
        },
        'sv-nav': {
          'active-fg': sv('color-nav-active-fg'),
          'active-bg': sv('color-nav-active-bg'),
          'inactive-fg': sv('color-nav-inactive-fg'),
        },
        'sv-footer': {
          surface: sv('color-footer-surface'),
          text: sv('color-footer-text'),
          border: sv('color-footer-border'),
        },

        // --- legacy aliases (Phase 4/11 pages) ---
        // These resolve through the GENERATED `--color-*` variables, which are themselves aliases
        // of the semantic tokens above. One source of colour truth; no drift possible. Migrating
        // the legacy pages off these names belongs to UI-08 … UI-15, not to UI-04.
        primary: 'var(--color-primary)',
        sun: 'var(--color-sun)',
        success: 'var(--color-success)',
        growth: 'var(--color-growth)',
        'brand-deep': 'var(--color-brand-deep)',
        heading: 'var(--color-heading)',
        accent: 'var(--color-accent)',
        warning: 'var(--color-warning)',
        error: 'var(--color-error)',
        info: 'var(--color-info)',
        text: {
          DEFAULT: 'var(--color-text)',
          muted: 'var(--color-text-muted)',
        },
        border: 'var(--color-border)',
        surface: {
          DEFAULT: 'var(--color-surface)',
          alt: 'var(--color-surface-alt)',
        },
        bg: 'var(--color-bg)',
        cream: 'var(--color-cream)',
      },
      borderRadius: {
        control: sv('radius-control'),
        card: sv('radius-card'),
        overlay: sv('radius-overlay'),
      },
      boxShadow: {
        card: sv('shadow-card'),
        raised: sv('shadow-raised'),
        overlay: sv('shadow-overlay'),
      },
      spacing: {
        'sv-gutter': sv('gutter-mobile'),
        'sv-touch': sv('touch-target-min'),
        'sv-sidebar': sv('sidebar-width'),
        'sv-rail': sv('sidebar-rail-width'),
        'sv-drawer': sv('drawer-width'),
      },
      maxWidth: {
        'sv-profile-identity': sv('profile-identity-max-width'),
        'sv-content': sv('content-max-width'),
        'sv-readable': sv('content-readable-width'),
        'sv-dialog-sm': sv('dialog-width-sm'),
        'sv-dialog-md': sv('dialog-width-md'),
        'sv-dialog-lg': sv('dialog-width-lg'),
      },
      minHeight: {
        'sv-touch': sv('touch-target-min'),
        'sv-control': sv('control-height-md'),
      },
      minWidth: {
        'sv-touch': sv('touch-target-min'),
      },
      zIndex: {
        'sv-sticky': sv('z-sticky'),
        'sv-footer': sv('z-footer'),
        'sv-drawer': sv('z-drawer'),
        'sv-dialog': sv('z-dialog'),
        'sv-popover': sv('z-popover'),
        'sv-toast': sv('z-toast'),
      },
      transitionDuration: {
        'sv-instant': sv('motion-duration-instant'),
        'sv-fast': sv('motion-duration-fast'),
        'sv-normal': sv('motion-duration-normal'),
      },
      transitionTimingFunction: {
        'sv-standard': sv('motion-ease-standard'),
        'sv-exit': sv('motion-ease-exit'),
      },
      fontFamily: {
        // Inter for product UI, Manrope for page titles (UI/UX plan §9.3).
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
} satisfies Config;
