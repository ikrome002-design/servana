import js from '@eslint/js';
import globals from 'globals';
import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

// Flat config (ESLint 10). Lints the Vue 3 + TypeScript SPA. No jQuery, no JS
// device detection (CLAUDE.md §6.1) — enforced by review + the absence of those
// deps; the no-restricted-imports rule below blocks jQuery at the lint layer.
export default tseslint.config(
  {
    ignores: [
      'public/**',
      'vendor/**',
      'node_modules/**',
      'storage/**',
      'bootstrap/cache/**',
      // Auto-generated from docs/api/openapi.json (npm run api:types). Validated by
      // vue-tsc + the contract check, not hand-edited, so it is not linted.
      'resources/spa/src/types/generated/**',
      // Auto-generated role content and landing-image manifest (Phase UI-05,
      // `npm run content:generate` / `npm run assets:generate`). These carry the approved
      // documents verbatim, are never hand-edited, and are validated by vue-tsc plus the
      // `content:check` / `assets:check` staleness gates rather than by lint.
      'resources/spa/src/content/generated/**',
      // Node tooling scripts (not part of the SPA bundle).
      'scripts/**',
    ],
  },
  // The SPA runs in the browser. eslint-plugin-vue 9 supplied these globals
  // implicitly from its flat base config; v10 no longer does, so declare them
  // here to keep `no-undef` accurate for browser APIs (REM-DEP-002).
  {
    languageOptions: {
      globals: { ...globals.browser },
    },
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },
  {
    files: ['**/*.{ts,vue}'],
    rules: {
      'no-restricted-imports': ['error', { patterns: ['jquery', 'jquery/*'] }],
      // Page/view components are legitimately single-word (Login, Verify,
      // Dashboard, Home) per the Plan §6.1 pages/ structure. The multi-word
      // rule fights that convention without adding value.
      'vue/multi-word-component-names': 'off',
    },
  },
);
