import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useGetStartedStore, storageKey } from '@/stores/getStartedStore';
import { CHECKLIST_SCHEMA_VERSION, getStartedChecklist } from '@/content/getStartedContent';
import type { RoleIdentity } from '@/types/roles';

const USER = '01JUSER0000000000000000000';
const OTHER_USER = '01JUSER1111111111111111111';
const ROLE: RoleIdentity = 'merchant_front_office';
const FIRST_ITEM = getStartedChecklist(ROLE)[0].id;

describe('getStartedStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
  });

  it('persists completion and survives a reload', () => {
    const store = useGetStartedStore();
    store.setCompleted(USER, ROLE, FIRST_ITEM, true);
    expect(store.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(true);

    // Simulate a reload: fresh pinia + store reads back from localStorage.
    setActivePinia(createPinia());
    const reloaded = useGetStartedStore();
    expect(reloaded.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(true);
  });

  it('toggles completion off again', () => {
    const store = useGetStartedStore();
    store.toggle(USER, ROLE, FIRST_ITEM);
    expect(store.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(true);
    store.toggle(USER, ROLE, FIRST_ITEM);
    expect(store.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(false);
  });

  it('dismisses and reopens', () => {
    const store = useGetStartedStore();
    expect(store.isDismissed(USER, ROLE)).toBe(false);
    store.dismiss(USER, ROLE);
    expect(store.isDismissed(USER, ROLE)).toBe(true);

    setActivePinia(createPinia());
    const reloaded = useGetStartedStore();
    expect(reloaded.isDismissed(USER, ROLE)).toBe(true);
    reloaded.reopen(USER, ROLE);
    expect(reloaded.isDismissed(USER, ROLE)).toBe(false);
  });

  it('isolates state by user and by role', () => {
    const store = useGetStartedStore();
    store.setCompleted(USER, ROLE, FIRST_ITEM, true);

    // Different user — independent.
    expect(store.isCompleted(OTHER_USER, ROLE, FIRST_ITEM)).toBe(false);
    // Different role for same user — independent.
    expect(store.isCompleted(USER, 'merchant_finance', getStartedChecklist('merchant_finance')[0].id)).toBe(false);
  });

  it('records the legal acknowledgement and completes the acknowledge item', () => {
    const store = useGetStartedStore();
    const ackItem = getStartedChecklist(ROLE).find((i) => i.kind === 'acknowledge');
    expect(ackItem).toBeTruthy();
    expect(store.isLegalAcknowledged(USER, ROLE)).toBe(false);
    store.acknowledgeLegal(USER, ROLE);
    expect(store.isLegalAcknowledged(USER, ROLE)).toBe(true);
    expect(store.isCompleted(USER, ROLE, ackItem!.id)).toBe(true);
  });

  it('reports progress out of the role checklist length', () => {
    const store = useGetStartedStore();
    const total = getStartedChecklist(ROLE).length;
    store.setCompleted(USER, ROLE, FIRST_ITEM, true);
    const p = store.progress(USER, ROLE);
    expect(p.total).toBe(total);
    expect(p.completed).toBe(1);
    expect(p.percent).toBe(Math.round((1 / total) * 100));
  });

  it('persists only non-sensitive fields', () => {
    const store = useGetStartedStore();
    store.setCompleted(USER, ROLE, FIRST_ITEM, true);
    store.acknowledgeLegal(USER, ROLE);
    const raw = localStorage.getItem(storageKey(USER, ROLE));
    expect(raw).toBeTruthy();
    const parsed = JSON.parse(raw!) as Record<string, unknown>;
    expect(Object.keys(parsed).sort()).toEqual(
      ['completed', 'dismissed', 'legalAcknowledged', 'schemaVersion'].sort(),
    );
    expect(parsed.schemaVersion).toBe(CHECKLIST_SCHEMA_VERSION);
  });

  it('discards stored data from a different schema version', () => {
    const key = storageKey(USER, ROLE);
    localStorage.setItem(
      key,
      JSON.stringify({ schemaVersion: 999, completed: [FIRST_ITEM], dismissed: true, legalAcknowledged: true }),
    );
    const store = useGetStartedStore();
    expect(store.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(false);
    expect(store.isDismissed(USER, ROLE)).toBe(false);
  });

  it('prunes unknown item ids on load', () => {
    const key = storageKey(USER, ROLE);
    localStorage.setItem(
      key,
      JSON.stringify({
        schemaVersion: CHECKLIST_SCHEMA_VERSION,
        completed: [FIRST_ITEM, 'a-removed-item-id'],
        dismissed: false,
        legalAcknowledged: false,
      }),
    );
    const store = useGetStartedStore();
    expect(store.isCompleted(USER, ROLE, FIRST_ITEM)).toBe(true);
    expect(store.isCompleted(USER, ROLE, 'a-removed-item-id')).toBe(false);
  });
});
