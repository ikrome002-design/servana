/// <reference types="vite/client" />

// Approved role content is imported verbatim from docs/** via `?raw` (Phase 11).
declare module '*.md?raw' {
  const content: string;
  export default content;
}
