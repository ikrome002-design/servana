/**
 * Vitest environment setup (Phase UI-04).
 *
 * jsdom implements no scrolling API: `window.scrollTo` is present only as a stub that raises
 * "Not implemented". `useFocusTrap` legitimately calls it when releasing an overlay's scroll lock,
 * to restore the position the page held before the overlay opened — correct browser behaviour that
 * must not be removed to suit the test environment.
 *
 * That call sits inside a Vue `watch` callback, so Vue's `callWithErrorHandling` catches the jsdom
 * error and routes it to `app.config.errorHandler`. Any spec installing such a handler therefore
 * recorded a jsdom limitation as a product exception — `Ui01Render001.spec.ts`, which asserts the
 * twelve audited routes raise NO uncaught error, is exactly that shape. The watcher flush is
 * asynchronous, so which test observed the stray error varied from run to run, which is what made
 * the failure intermittent rather than reproducible.
 *
 * Supplying the missing API is the correct layer: the gap belongs to the environment, not to the
 * assertion and not to the component.
 */
Object.defineProperty(window, 'scrollTo', {
  value: () => {},
  writable: true,
  configurable: true,
});
