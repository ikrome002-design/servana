// GENERATED FILE — do not edit by hand.
//
// Source:     resources/spa/src/design-system/tokens.json
// Generator:  node scripts/generate-design-tokens.mjs
// Verify:     node scripts/generate-design-tokens.mjs --check
// Source SHA-256: 2e3bcf29894c6eff5dec3ec5b74438698d0f7027852cef81e1882d0a6b2dbe0a
//
// Typed access to the Servana design tokens (ADR-021). Components should consume the CSS custom
// properties (via Tailwind's semantic aliases) rather than these constants; these exist so tests,
// contrast guards and the design-system fixture can reason about token VALUES deterministically.

export const DESIGN_TOKEN_SCHEMA_VERSION = '1.0.0';
export const DESIGN_TOKEN_SOURCE_SHA256 = '2e3bcf29894c6eff5dec3ec5b74438698d0f7027852cef81e1882d0a6b2dbe0a';

export type ThemeName = 'light' | 'dark';

export type SemanticColorToken =
  | 'color-brand-primary'
  | 'color-brand-primary-hover'
  | 'color-brand-secondary'
  | 'color-accent'
  | 'color-surface-page'
  | 'color-surface-raised'
  | 'color-surface-subtle'
  | 'color-surface-warm'
  | 'color-overlay-scrim'
  | 'color-text-primary'
  | 'color-text-secondary'
  | 'color-text-muted'
  | 'color-text-inverse'
  | 'color-text-on-brand'
  | 'color-text-heading'
  | 'color-border-default'
  | 'color-border-strong'
  | 'color-border-input'
  | 'color-focus-ring'
  | 'color-link'
  | 'color-link-hover'
  | 'color-status-success-fg'
  | 'color-status-success-bg'
  | 'color-status-success-border'
  | 'color-status-warning-fg'
  | 'color-status-warning-bg'
  | 'color-status-warning-border'
  | 'color-status-error-fg'
  | 'color-status-error-bg'
  | 'color-status-error-border'
  | 'color-status-info-fg'
  | 'color-status-info-bg'
  | 'color-status-info-border'
  | 'color-disabled-fg'
  | 'color-disabled-bg'
  | 'color-disabled-border'
  | 'color-selected-fg'
  | 'color-selected-bg'
  | 'color-selected-border'
  | 'color-table-header'
  | 'color-table-row-hover'
  | 'color-nav-active-fg'
  | 'color-nav-active-bg'
  | 'color-nav-inactive-fg'
  | 'color-footer-surface'
  | 'color-footer-text'
  | 'color-footer-border'
  | 'color-growth';

export type ComponentToken =
  | 'space-0'
  | 'space-1'
  | 'space-2'
  | 'space-3'
  | 'space-4'
  | 'space-5'
  | 'space-6'
  | 'space-8'
  | 'space-10'
  | 'space-12'
  | 'gutter-mobile'
  | 'gutter-tablet'
  | 'gutter-desktop'
  | 'radius-none'
  | 'radius-control'
  | 'radius-card'
  | 'radius-overlay'
  | 'radius-pill'
  | 'border-width-hairline'
  | 'border-width-strong'
  | 'shadow-card'
  | 'shadow-raised'
  | 'shadow-overlay'
  | 'focus-ring-width'
  | 'focus-ring-offset'
  | 'motion-duration-instant'
  | 'motion-duration-fast'
  | 'motion-duration-normal'
  | 'motion-ease-standard'
  | 'motion-ease-exit'
  | 'z-base'
  | 'z-sticky'
  | 'z-footer'
  | 'z-drawer'
  | 'z-dialog'
  | 'z-popover'
  | 'z-toast'
  | 'header-height-mobile'
  | 'header-height-desktop'
  | 'sidebar-width'
  | 'sidebar-rail-width'
  | 'drawer-width'
  | 'profile-identity-max-width'
  | 'content-max-width'
  | 'content-readable-width'
  | 'dialog-width-sm'
  | 'dialog-width-md'
  | 'dialog-width-lg'
  | 'footer-height-mobile'
  | 'footer-height-tablet'
  | 'footer-height-desktop'
  | 'footer-height-zoom-fallback'
  | 'control-height-sm'
  | 'control-height-md'
  | 'control-height-lg'
  | 'touch-target-min'
  | 'table-row-height-compact'
  | 'table-row-height-default'
  | 'table-cell-padding-x'
  | 'table-cell-padding-y'
  | 'safe-area-bottom'
  | 'safe-area-left'
  | 'safe-area-right';

/** Raw brand palette. Present for provenance and tests — never consume directly in a component. */
export const PALETTE: Readonly<Record<string, string>> = Object.freeze({
  'savannah-orange': "#F97316",
  'golden-sun': "#FBBF24",
  'acacia-green': "#3F7D20",
  'deep-earth-brown': "#4A2208",
  'service-teal': "#007C78",
  'warm-sand': "#FFF3C4",
  'savannah-cream': "#FFF8E7",
  'charcoal': "#1F2933",
  'soft-gray': "#F3F4F6",
  'app-background': "#F9FAFB",
  'status-success': "#2E7D32",
  'status-warning': "#F59E0B",
  'status-error': "#DC2626",
  'status-info': "#0284C7",
  'neutral-text': "#374151",
  'muted-text': "#6B7280",
  'border': "#E5E7EB",
  'white': "#FFFFFF",
  'gray-200': "#E5E7EB",
  'gray-300': "#D1D5DB",
  'gray-400': "#9CA3AF",
  'gray-600': "#4B5563",
  'gray-700': "#374151",
  'gray-800': "#273340",
  'gray-900': "#111827",
  'green-100': "#E7F5E8",
  'green-300': "#86EFAC",
  'green-400': "#4ADE80",
  'green-800': "#1B5E20",
  'green-950': "#14321B",
  'amber-100': "#FEF3C7",
  'amber-300': "#FCD34D",
  'amber-700': "#B45309",
  'amber-800': "#92400E",
  'amber-950': "#3A2A08",
  'red-100': "#FEE2E2",
  'red-300': "#FCA5A5",
  'red-400': "#F87171",
  'red-700': "#B91C1C",
  'red-950': "#3B1516",
  'sky-100': "#E0F2FE",
  'sky-300': "#7DD3FC",
  'sky-400': "#38BDF8",
  'sky-800': "#075985",
  'sky-950': "#0C2A3E",
  'teal-400': "#14B8A6",
  'orange-800': "#9A3412",
  'orange-300': "#FDBA74",
});

export const SEMANTIC_COLORS: Readonly<Record<ThemeName, Readonly<Record<SemanticColorToken, string>>>> =
  Object.freeze({
    light: Object.freeze({
      'color-brand-primary': "#F97316",
      'color-brand-primary-hover': "#FDBA74",
      'color-brand-secondary': "#007C78",
      'color-accent': "#FBBF24",
      'color-surface-page': "#F9FAFB",
      'color-surface-raised': "#FFFFFF",
      'color-surface-subtle': "#F3F4F6",
      'color-surface-warm': "#FFF8E7",
      'color-overlay-scrim': "rgb(31 41 51 / 0.55)",
      'color-text-primary': "#1F2933",
      'color-text-secondary': "#374151",
      'color-text-muted': "#4B5563",
      'color-text-inverse': "#FFFFFF",
      'color-text-on-brand': "#4A2208",
      'color-text-heading': "#4A2208",
      'color-border-default': "#E5E7EB",
      'color-border-strong': "#D1D5DB",
      'color-border-input': "#6B7280",
      'color-focus-ring': "#007C78",
      'color-link': "#007C78",
      'color-link-hover': "#4A2208",
      'color-status-success-fg': "#1B5E20",
      'color-status-success-bg': "#E7F5E8",
      'color-status-success-border': "#2E7D32",
      'color-status-warning-fg': "#92400E",
      'color-status-warning-bg': "#FEF3C7",
      'color-status-warning-border': "#B45309",
      'color-status-error-fg': "#B91C1C",
      'color-status-error-bg': "#FEE2E2",
      'color-status-error-border': "#DC2626",
      'color-status-info-fg': "#075985",
      'color-status-info-bg': "#E0F2FE",
      'color-status-info-border': "#0284C7",
      'color-disabled-fg': "#4B5563",
      'color-disabled-bg': "#F3F4F6",
      'color-disabled-border': "#D1D5DB",
      'color-selected-fg': "#4A2208",
      'color-selected-bg': "#FFF3C4",
      'color-selected-border': "#F97316",
      'color-table-header': "#F3F4F6",
      'color-table-row-hover': "#FFF8E7",
      'color-nav-active-fg': "#4A2208",
      'color-nav-active-bg': "#FFF3C4",
      'color-nav-inactive-fg': "#4B5563",
      'color-footer-surface': "#FFFFFF",
      'color-footer-text': "#4B5563",
      'color-footer-border': "#E5E7EB",
      'color-growth': "#3F7D20",
    }),
    dark: Object.freeze({
      'color-brand-primary': "#F97316",
      'color-brand-primary-hover': "#FDBA74",
      'color-brand-secondary': "#14B8A6",
      'color-accent': "#FBBF24",
      'color-surface-page': "#111827",
      'color-surface-raised': "#1F2933",
      'color-surface-subtle': "#273340",
      'color-surface-warm': "#273340",
      'color-overlay-scrim': "rgb(0 0 0 / 0.65)",
      'color-text-primary': "#F3F4F6",
      'color-text-secondary': "#D1D5DB",
      'color-text-muted': "#9CA3AF",
      'color-text-inverse': "#1F2933",
      'color-text-on-brand': "#4A2208",
      'color-text-heading': "#F3F4F6",
      'color-border-default': "#374151",
      'color-border-strong': "#4B5563",
      'color-border-input': "#9CA3AF",
      'color-focus-ring': "#FBBF24",
      'color-link': "#14B8A6",
      'color-link-hover': "#FBBF24",
      'color-status-success-fg': "#86EFAC",
      'color-status-success-bg': "#14321B",
      'color-status-success-border': "#4ADE80",
      'color-status-warning-fg': "#FCD34D",
      'color-status-warning-bg': "#3A2A08",
      'color-status-warning-border': "#FBBF24",
      'color-status-error-fg': "#FCA5A5",
      'color-status-error-bg': "#3B1516",
      'color-status-error-border': "#F87171",
      'color-status-info-fg': "#7DD3FC",
      'color-status-info-bg': "#0C2A3E",
      'color-status-info-border': "#38BDF8",
      'color-disabled-fg': "#9CA3AF",
      'color-disabled-bg': "#273340",
      'color-disabled-border': "#4B5563",
      'color-selected-fg': "#F3F4F6",
      'color-selected-bg': "#273340",
      'color-selected-border': "#F97316",
      'color-table-header': "#273340",
      'color-table-row-hover': "#273340",
      'color-nav-active-fg': "#F3F4F6",
      'color-nav-active-bg': "#273340",
      'color-nav-inactive-fg': "#9CA3AF",
      'color-footer-surface': "#1F2933",
      'color-footer-text': "#9CA3AF",
      'color-footer-border': "#374151",
      'color-growth': "#4ADE80",
    }),
  }) as Readonly<Record<ThemeName, Readonly<Record<SemanticColorToken, string>>>>;

export const COMPONENT_TOKENS: Readonly<Record<ComponentToken, string>> = Object.freeze({
  'space-0': "0",
  'space-1': "0.25rem",
  'space-2': "0.5rem",
  'space-3': "0.75rem",
  'space-4': "1rem",
  'space-5': "1.25rem",
  'space-6': "1.5rem",
  'space-8': "2rem",
  'space-10': "2.5rem",
  'space-12': "3rem",
  'gutter-mobile': "1rem",
  'gutter-tablet': "1.5rem",
  'gutter-desktop': "2rem",
  'radius-none': "0",
  'radius-control': "8px",
  'radius-card': "12px",
  'radius-overlay': "16px",
  'radius-pill': "9999px",
  'border-width-hairline': "1px",
  'border-width-strong': "2px",
  'shadow-card': "0 1px 3px rgb(0 0 0 / 0.08)",
  'shadow-raised': "0 4px 12px rgb(0 0 0 / 0.10)",
  'shadow-overlay': "0 16px 40px rgb(0 0 0 / 0.18)",
  'focus-ring-width': "2px",
  'focus-ring-offset': "2px",
  'motion-duration-instant': "80ms",
  'motion-duration-fast': "150ms",
  'motion-duration-normal': "220ms",
  'motion-ease-standard': "cubic-bezier(0.2, 0, 0.13, 1)",
  'motion-ease-exit': "cubic-bezier(0.4, 0, 1, 1)",
  'z-base': "0",
  'z-sticky': "10",
  'z-footer': "20",
  'z-drawer': "40",
  'z-dialog': "50",
  'z-popover': "60",
  'z-toast': "70",
  'header-height-mobile': "3.5rem",
  'header-height-desktop': "4rem",
  'sidebar-width': "16rem",
  'sidebar-rail-width': "4.5rem",
  'drawer-width': "18rem",
  'profile-identity-max-width': "12rem",
  'content-max-width': "80rem",
  'content-readable-width': "42rem",
  'dialog-width-sm': "24rem",
  'dialog-width-md': "32rem",
  'dialog-width-lg': "44rem",
  'footer-height-mobile': "5.5rem",
  'footer-height-tablet': "4.5rem",
  'footer-height-desktop': "3.5rem",
  'footer-height-zoom-fallback': "9rem",
  'control-height-sm': "2.25rem",
  'control-height-md': "2.75rem",
  'control-height-lg': "3rem",
  'touch-target-min': "44px",
  'table-row-height-compact': "2.5rem",
  'table-row-height-default': "3rem",
  'table-cell-padding-x': "0.75rem",
  'table-cell-padding-y': "0.5rem",
  'safe-area-bottom': "env(safe-area-inset-bottom, 0px)",
  'safe-area-left': "env(safe-area-inset-left, 0px)",
  'safe-area-right': "env(safe-area-inset-right, 0px)",
}) as Readonly<Record<ComponentToken, string>>;

/**
 * The binding viewport contract (UI/UX plan §13.2, CLAUDE.md guardrail 1).
 *
 * These numbers exist so a TEST can assert Tailwind and the CSS agree with the plan. They are
 * NEVER used at runtime to choose a layout: responsive behaviour is CSS media queries only, and
 * JavaScript device detection is forbidden.
 */
export const BREAKPOINTS = Object.freeze({
  mobileMaxPx: 767,
  tabletMinPx: 768,
  tabletMaxPx: 1024,
  desktopMinPx: 1025,
});

/** CSS variable reference for a semantic colour token. */
export function semanticVar(token: SemanticColorToken): string {
  return `var(--sv-${token})`;
}

/** CSS variable reference for a component token. */
export function componentVar(token: ComponentToken): string {
  return `var(--sv-${token})`;
}
