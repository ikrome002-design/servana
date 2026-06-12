import type { Config } from 'tailwindcss';

// Brand tokens are declared as CSS variables in resources/spa/src/style.css
// (Plan §12.1) so a single source drives both light and dark themes. Tailwind
// maps semantic utility names onto those variables here.
export default {
  content: [
    './resources/spa/index.html',
    './resources/spa/src/**/*.{vue,ts}',
  ],
  // Plan §14: class strategy, light default.
  darkMode: 'class',
  theme: {
    // Plan §13: CSS-only breakpoints. mobile <768, tablet 768-1024, desktop >=1025.
    // Overriding `screens` (not extending) removes Tailwind's sm/xl defaults so
    // only these two breakpoints exist, exactly as the scope mandates.
    screens: {
      md: '768px',
      lg: '1025px',
    },
    extend: {
      colors: {
        primary: 'var(--color-primary)',
        sun: 'var(--color-sun)',
        success: 'var(--color-success)',
        growth: 'var(--color-growth)',
        'brand-deep': 'var(--color-brand-deep)',
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
        card: 'var(--radius-card)',
        control: 'var(--radius-control)',
      },
      boxShadow: {
        card: 'var(--shadow-card)',
      },
      fontFamily: {
        // Inter for product UI, Manrope for page titles (Plan §12.2).
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
} satisfies Config;
