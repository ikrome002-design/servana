# Servana Design System

Delivered by **Phase UI-04** (ADR-021, ADR-024; UI/UX plan §9–§14).

This directory documents the contracts. The authoritative sources are code:

| Concern | Authority | Generated consumers |
|---|---|---|
| Tokens | `resources/spa/src/design-system/tokens.json` | `styles/generated/tokens.css`, `design-system/tokens.generated.ts` |
| Components | `resources/spa/src/design-system/componentRegistry.ts` | `docs/frontend/audits/ui-04/component-contracts.json` |
| Icons | `resources/spa/src/design-system/icons.ts` | — |
| Breakpoints | `tokens.json → breakpoints` | `tailwind.config.ts` reads it directly |

Regenerate with:

```bash
node scripts/generate-design-tokens.mjs
node scripts/generate-ui04-artifacts.mjs
```

Both accept `--check` and are wired into CI. **Never hand-edit a generated file.**

## The four rules that shape everything here

1. **One token authority.** Raw brand values exist in `tokens.json` and nowhere else.
   `DesignTokenSourceGuardTest` fails the build on a raw hex in production UI source.
2. **Light is the default.** The operating-system colour scheme is never consulted, in any layer
   (ADR-021). See [theme-contract.md](theme-contract.md).
3. **One implementation per contract.** UI-04 removed `SvInput`, `SvTextarea`, `SvModal` and the
   UI-03 `SvAccountSwitcher` rather than leaving aliases beside their canonical replacements.
4. **Honest states.** A component that has no loading state declares none, rather than
   implementing fake behaviour to fill a column.

## Contracts

- [token-contract.md](token-contract.md)
- [component-contract.md](component-contract.md)
- [theme-contract.md](theme-contract.md)
- [footer-contract.md](footer-contract.md)

## What UI-04 did not build

The shared foundation only. No landing pages, no role content, no account experiences, no
route contract. Owners are recorded in `docs/frontend/audits/ui-04/defect-closure.json`
under `not_done_in_ui04`.
