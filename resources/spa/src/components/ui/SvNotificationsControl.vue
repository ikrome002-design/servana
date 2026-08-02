<script setup lang="ts">
/**
 * SvNotificationsControl — the notifications affordance (Phase UI-04).
 *
 * ## Scope boundary — read this before wiring it to anything
 *
 * Servana has **no notification API and no notification store** today: there is no
 * `/api/v1/notifications` route, and `notificationStore` is the in-page TOAST queue, which is a
 * different thing entirely.
 *
 * So UI-04 delivers the VISUAL CONTRACT only. This component is fully data-driven — it receives
 * items, a loading flag and an error, and renders them. It creates no table, no controller, no
 * route and no fake record, and it is exercised in the design-system fixture rather than wired
 * into product navigation. Inventing notification data to make a bell look busy would be
 * fabricated evidence.
 *
 * When an authorized notification API exists, its owning phase supplies real items through these
 * props and the masking/tenancy rules stay where they belong — on the server.
 *
 * ## Contract
 *
 * The unread count is announced in the control's accessible NAME, never carried by the badge dot
 * alone — a coloured dot is invisible to a screen reader and to a monochrome display.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import { SvIconNotifications } from '@/design-system/icons';

export interface SvNotificationItem {
  id: string;
  title: string;
  body?: string;
  /** ISO-8601, or null when the record carries none. */
  at: string | null;
  read?: boolean;
}

const props = withDefaults(
  defineProps<{
    /** Supplied by the caller. This component fetches nothing. */
    items?: SvNotificationItem[];
    loading?: boolean;
    error?: string | null;
    emptyLabel?: string;
  }>(),
  {
    items: () => [],
    loading: false,
    error: null,
    emptyLabel: 'No notifications.',
  },
);

const emit = defineEmits<{ select: [id: string] }>();

const open = ref(false);
const triggerRef = ref<InstanceType<typeof SvIconButton> | null>(null);
const panelRef = ref<HTMLElement | null>(null);

const unreadCount = computed(() => props.items.filter((item) => item.read !== true).length);

/** The count lives in the accessible NAME, so it is never carried by the badge alone. */
const triggerLabel = computed(() =>
  unreadCount.value === 0
    ? 'Notifications'
    : `Notifications, ${unreadCount.value} unread`,
);

async function openPanel(): Promise<void> {
  open.value = true;
  await nextTick();
  panelRef.value?.focus();
}

function closePanel(returnFocus = true): void {
  open.value = false;
  if (returnFocus) {
    (triggerRef.value?.$el as HTMLElement | undefined)?.focus();
  }
}

function onPanelKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation();
    closePanel();
  }
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (panelRef.value?.contains(target ?? null) === true) {
    return;
  }
  if ((triggerRef.value?.$el as HTMLElement | undefined)?.contains(target ?? null) === true) {
    return;
  }
  closePanel(false);
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);

    return;
  }
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});
</script>

<template>
  <div
    class="relative"
    data-testid="sv-notifications-control"
  >
    <div class="relative">
      <SvIconButton
        ref="triggerRef"
        :icon="SvIconNotifications"
        :label="triggerLabel"
        :expanded="open"
        controls="sv-notifications-panel"
        data-testid="sv-notifications-trigger"
        @click="open ? closePanel(false) : openPanel()"
      />
      <!-- Purely visual reinforcement; the count is already in the control's name. -->
      <span
        v-if="unreadCount > 0"
        aria-hidden="true"
        class="pointer-events-none absolute right-1 top-1 min-w-4 rounded-pill bg-sv-error-border px-1 text-center text-[10px] font-semibold leading-4 text-sv-text-inverse"
        data-testid="sv-notifications-badge"
      >{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
    </div>

    <div
      v-if="open"
      id="sv-notifications-panel"
      ref="panelRef"
      role="region"
      aria-label="Notifications"
      tabindex="-1"
      class="absolute right-0 z-sv-popover mt-1 max-h-[60vh] w-max min-w-[18rem] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-card border border-sv-border bg-sv-surface-raised shadow-overlay focus:outline-none"
      @keydown="onPanelKeydown"
    >
      <p
        v-if="loading"
        class="px-3 py-4 text-sm text-sv-text-muted"
        data-testid="sv-notifications-loading"
      >
        Loading notifications…
      </p>
      <p
        v-else-if="error !== null"
        role="alert"
        class="px-3 py-4 text-sm text-sv-error-fg"
        data-testid="sv-notifications-error"
      >
        {{ error }}
      </p>
      <p
        v-else-if="items.length === 0"
        class="px-3 py-4 text-sm text-sv-text-muted"
        data-testid="sv-notifications-empty"
      >
        {{ emptyLabel }}
      </p>

      <ul v-else>
        <li
          v-for="item in items"
          :key="item.id"
        >
          <button
            type="button"
            class="sv-focus-ring flex min-h-sv-touch w-full flex-col items-start gap-0.5 border-b border-sv-border px-3 py-2 text-left last:border-b-0 hover:bg-sv-surface-subtle"
            :data-testid="`sv-notification-${item.id}`"
            @click="emit('select', item.id)"
          >
            <span class="flex w-full items-center gap-2">
              <span class="min-w-0 flex-1 truncate text-sm font-medium text-sv-text">{{ item.title }}</span>
              <!-- Unread is stated, not signalled by weight or colour alone. -->
              <span
                v-if="item.read !== true"
                class="shrink-0 text-xs font-semibold text-sv-brand-secondary"
              >Unread</span>
            </span>
            <span
              v-if="item.body"
              class="line-clamp-2 text-xs text-sv-text-muted"
            >{{ item.body }}</span>
            <SvDateTime
              :value="item.at"
              class="text-xs text-sv-text-muted"
            />
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
