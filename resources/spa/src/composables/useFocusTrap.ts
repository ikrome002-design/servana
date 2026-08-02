import { nextTick, onBeforeUnmount, ref, watch, type Ref } from 'vue';

/**
 * Shared overlay focus management (Phase UI-04; UI/UX plan §10, §19).
 *
 * ONE implementation, used by `SvDialog`, `SvConfirmDialog` and `SvDrawer`. Two independent focus
 * traps in one application is how they drift: one restores focus and the other does not, one
 * handles Shift+Tab and the other does not, and the difference only shows up for keyboard users.
 *
 * What it guarantees while an overlay is open:
 *
 *  - focus moves INTO the overlay (an explicit initial target, else the first focusable element,
 *    else the container itself, which is why the container is given `tabindex="-1"`);
 *  - Tab and Shift+Tab cycle within the overlay rather than escaping to the page behind it;
 *  - focus RETURNS to the element that opened the overlay on close — losing focus to `<body>`
 *    strands a keyboard user at the top of the document;
 *  - the page behind cannot scroll, and its scroll position is preserved rather than jumping to
 *    the top when the lock is released.
 *
 * It deliberately does NOT own Escape. Whether Escape closes is per-overlay policy — a confirm
 * dialog mid-submission should not vanish — so each component decides and this stays mechanical.
 */

/** Elements that can hold focus. `[hidden]` and negative tabindex are excluded deliberately. */
const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'details > summary',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

export interface UseFocusTrapOptions {
  /** The overlay container. Must carry `tabindex="-1"` so it can receive focus when empty. */
  container: Ref<HTMLElement | null>;
  /** Whether the overlay is currently open. */
  open: Ref<boolean>;
  /** Optional element to focus first, e.g. Cancel in a destructive confirm dialog. */
  initialFocus?: Ref<HTMLElement | null>;
  /** Lock scrolling on the page behind. Default true. */
  lockScroll?: boolean;
}

export function useFocusTrap(options: UseFocusTrapOptions): {
  focusables: () => HTMLElement[];
} {
  const { container, open, initialFocus, lockScroll = true } = options;

  /** The element that had focus when the overlay opened, so it can be restored. */
  const previouslyFocused = ref<HTMLElement | null>(null);
  /** The page scroll offset at lock time, so releasing the lock does not jump to the top. */
  const lockedScrollY = ref(0);

  function focusables(): HTMLElement[] {
    if (container.value === null) {
      return [];
    }

    return Array.from(container.value.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
      (element) => element.offsetParent !== null || element === document.activeElement,
    );
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || !open.value || container.value === null) {
      return;
    }

    const items = focusables();
    if (items.length === 0) {
      // Nothing focusable inside: keep focus on the container rather than letting it escape.
      event.preventDefault();
      container.value.focus();

      return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || active === container.value)) {
      event.preventDefault();
      last.focus();

      return;
    }
    if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function applyScrollLock(): void {
    if (!lockScroll) {
      return;
    }
    lockedScrollY.value = window.scrollY;
    document.body.style.overflow = 'hidden';
  }

  function releaseScrollLock(): void {
    if (!lockScroll) {
      return;
    }
    document.body.style.overflow = '';
    // Restore the position: releasing `overflow: hidden` otherwise leaves the page where the
    // browser put it, which reads as the page having jumped while the overlay was open.
    window.scrollTo({ top: lockedScrollY.value, behavior: 'auto' });
  }

  watch(open, async (isOpen, wasOpen) => {
    if (isOpen === wasOpen) {
      return;
    }

    if (isOpen) {
      previouslyFocused.value = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      applyScrollLock();
      document.addEventListener('keydown', onKeydown, true);

      await nextTick();
      const target = initialFocus?.value ?? focusables()[0] ?? container.value;
      target?.focus();

      return;
    }

    document.removeEventListener('keydown', onKeydown, true);
    releaseScrollLock();
    // Return focus to whatever opened the overlay; losing it to <body> strands a keyboard user.
    previouslyFocused.value?.focus();
    previouslyFocused.value = null;
  });

  onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown, true);
    if (open.value) {
      releaseScrollLock();
    }
  });

  return { focusables };
}
