import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { RoleIdentity } from '@/types/roles';
import {
  CHECKLIST_SCHEMA_VERSION,
  getStartedChecklist,
} from '@/content/getStartedContent';

/**
 * Guided get-started progress (Plan §27.2; Scope §3.2). No server preference API
 * exists in the repository, so progress is persisted in versioned, non-sensitive
 * localStorage keyed by authenticated user ULID + active role identity. We store
 * ONLY checklist item ids, completion/dismissal/acknowledgement booleans, and a
 * schema version — never tokens, permissions, contacts, secrets, signed URLs,
 * storage paths, or API responses (Plan §2.1 rule 10; task persistence rules).
 * Persistence is device-specific by design.
 */
export interface ChecklistState {
  schemaVersion: number;
  completed: string[];
  dismissed: boolean;
  legalAcknowledged: boolean;
}

export interface ChecklistProgress {
  completed: number;
  total: number;
  percent: number;
}

function emptyState(): ChecklistState {
  return {
    schemaVersion: CHECKLIST_SCHEMA_VERSION,
    completed: [],
    dismissed: false,
    legalAcknowledged: false,
  };
}

/** Storage key — namespaced, versioned, and isolated per user + role. */
export function storageKey(userId: string, identity: RoleIdentity): string {
  return `servana.get-started.v${CHECKLIST_SCHEMA_VERSION}.${userId}.${identity}`;
}

export const useGetStartedStore = defineStore('getStarted', () => {
  // In-memory cache of loaded scopes; the source of truth is localStorage.
  const scopes = ref<Record<string, ChecklistState>>({});

  function read(userId: string, identity: RoleIdentity): ChecklistState {
    const key = storageKey(userId, identity);
    const cached = scopes.value[key];
    if (cached) return cached;

    let state = emptyState();
    try {
      const raw = localStorage.getItem(key);
      if (raw) {
        const parsed = JSON.parse(raw) as Partial<ChecklistState>;
        // A schema-version mismatch discards old data rather than misinterpret it.
        if (parsed.schemaVersion === CHECKLIST_SCHEMA_VERSION) {
          const validIds = new Set(getStartedChecklist(identity).map((i) => i.id));
          state = {
            schemaVersion: CHECKLIST_SCHEMA_VERSION,
            completed: Array.isArray(parsed.completed)
              ? parsed.completed.filter((id) => typeof id === 'string' && validIds.has(id))
              : [],
            dismissed: parsed.dismissed === true,
            legalAcknowledged: parsed.legalAcknowledged === true,
          };
        }
      }
    } catch {
      state = emptyState();
    }
    scopes.value = { ...scopes.value, [key]: state };
    return state;
  }

  function write(userId: string, identity: RoleIdentity, next: ChecklistState): void {
    const key = storageKey(userId, identity);
    scopes.value = { ...scopes.value, [key]: next };
    try {
      localStorage.setItem(key, JSON.stringify(next));
    } catch {
      // localStorage unavailable; in-memory state still updates this session.
    }
  }

  function isCompleted(userId: string, identity: RoleIdentity, itemId: string): boolean {
    return read(userId, identity).completed.includes(itemId);
  }

  function setCompleted(
    userId: string,
    identity: RoleIdentity,
    itemId: string,
    value: boolean,
  ): void {
    const state = read(userId, identity);
    const has = state.completed.includes(itemId);
    if (value === has) return;
    const completed = value
      ? [...state.completed, itemId]
      : state.completed.filter((id) => id !== itemId);
    write(userId, identity, { ...state, completed });
  }

  function toggle(userId: string, identity: RoleIdentity, itemId: string): void {
    setCompleted(userId, identity, itemId, !isCompleted(userId, identity, itemId));
  }

  function isDismissed(userId: string, identity: RoleIdentity): boolean {
    return read(userId, identity).dismissed;
  }

  function dismiss(userId: string, identity: RoleIdentity): void {
    write(userId, identity, { ...read(userId, identity), dismissed: true });
  }

  function reopen(userId: string, identity: RoleIdentity): void {
    write(userId, identity, { ...read(userId, identity), dismissed: false });
  }

  function isLegalAcknowledged(userId: string, identity: RoleIdentity): boolean {
    return read(userId, identity).legalAcknowledged;
  }

  function acknowledgeLegal(userId: string, identity: RoleIdentity): void {
    const state = read(userId, identity);
    const ackItem = getStartedChecklist(identity).find((i) => i.kind === 'acknowledge');
    const completed =
      ackItem && !state.completed.includes(ackItem.id)
        ? [...state.completed, ackItem.id]
        : state.completed;
    write(userId, identity, { ...state, legalAcknowledged: true, completed });
  }

  function progress(userId: string, identity: RoleIdentity): ChecklistProgress {
    const items = getStartedChecklist(identity);
    const state = read(userId, identity);
    const completed = items.filter((i) => state.completed.includes(i.id)).length;
    const total = items.length;
    const percent = total === 0 ? 0 : Math.round((completed / total) * 100);
    return { completed, total, percent };
  }

  function $reset(): void {
    scopes.value = {};
  }

  return {
    scopes,
    read,
    isCompleted,
    setCompleted,
    toggle,
    isDismissed,
    dismiss,
    reopen,
    isLegalAcknowledged,
    acknowledgeLegal,
    progress,
    $reset,
  };
});
