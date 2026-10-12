import type { InjectionKey, Ref } from 'vue';
import type { RoleIdentity } from '@/types/roles';

/**
 * Account accent for the tonal page header (Phase UI-16).
 *
 * `SvTonalPageHeader` washes its surface in one accent. UI-14 and UI-15 pages pass `tone`
 * explicitly per page; the 47 older-account pages that render `SvPageHeader` never did, so they had
 * no accent at all. The shell provides the ACCOUNT's default accent here; an explicit `tone` prop
 * always wins. The tone is decoration only — it never carries meaning, and no status is expressed by
 * it (UI/UX plan §9.2).
 */
export type PageTone = 'warm' | 'teal' | 'green';

export const PAGE_TONE_KEY: InjectionKey<Ref<PageTone>> = Symbol('sv-page-tone');

const ACCOUNT_TONE: Readonly<Record<RoleIdentity, PageTone>> = {
  super_administrator: 'warm',
  merchant_administrator: 'warm',
  merchant_branch: 'green',
  merchant_human_resource: 'green',
  merchant_finance: 'teal',
  merchant_front_office: 'warm',
  merchant_personnel: 'green',
  merchant_audit: 'teal',
};

export function accountTone(identity: RoleIdentity): PageTone {
  return ACCOUNT_TONE[identity];
}
