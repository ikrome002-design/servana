import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

// Flat config (ESLint 9). Lints the Vue 3 + TypeScript SPA. No jQuery, no JS
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
      // Node tooling scripts (not part of the SPA bundle).
      'scripts/**',
    ],
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
