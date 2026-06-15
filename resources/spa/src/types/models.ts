import type { MerchantRole, MerchantStatus, UserStatus } from './enums';

export interface User {
  ulid: string;
  email: string;
  first_name: string;
  last_name: string;
  display_name: string;
  status: UserStatus;
  is_platform_staff: boolean;
}

/** The authenticated user object inside the bootstrap payload (Plan §6.2). */
export interface AuthenticatedUser {
  id: string;
  email: string;
  name: string;
  status: UserStatus;
  email_verified_at: string | null;
  is_platform_staff: boolean;
}

export type ServiceFeeTier = 'customer_centric' | 'split_tier' | 'business_centric';

export interface Merchant {
  id: string;
  name: string;
  slug: string;
  status: MerchantStatus;
  service_fee_tier: ServiceFeeTier | null;
  setup_completed_at: string | null;
}

export type MembershipStatus = 'invited' | 'active' | 'suspended' | 'deactivated';

export interface MerchantMembership {
  id: string;
  role: MerchantRole;
  status: MembershipStatus;
}

/**
 * First-time setup state (Scope §3.2), surfaced so the SPA can route a
 * pending_setup owner to the wizard and an active owner to the dashboard.
 */
export interface SetupState {
  required: boolean;
  current_step: string | null;
  completed_at: string | null;
}

/**
 * Bootstrap payload returned by GET /api/v1/me and the Magic Link verify
 * endpoint (Plan §6.2, §8.1). `permissions` carries the resolved §10.3
 * permission keys (Phase 8) — UX only; the API is the authorization boundary.
 */
export interface BootstrapPayload {
  user: AuthenticatedUser;
  merchant: Merchant | null;
  membership: MerchantMembership | null;
  memberships: MerchantMembership[];
  permissions: string[];
  setup: SetupState;
  branch_ids: string[];
}

export type BranchStatus = 'active' | 'suspended' | 'archived';

export interface Branch {
  id: string;
  name: string;
  code: string;
  address: string | null;
  town: string | null;
  phone: string | null;
  email: string | null;
  business_category: string | null;
  status: BranchStatus;
  status_reason: string | null;
  archived_at: string | null;
}

export interface BranchOperatingHour {
  weekday: number;
  opens_at: string | null;
  closes_at: string | null;
  is_closed: boolean;
  break_start: string | null;
  break_end: string | null;
}

export type StaffInvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired';

export interface StaffInvitation {
  id: string;
  email: string;
  role: MerchantRole;
  role_title: string | null;
  branch_id: string | null;
  status: StaffInvitationStatus;
  resend_count: number;
  expires_at: string;
  last_sent_at: string | null;
}

export interface StaffProfile {
  id: string;
  first_name: string;
  last_name: string;
  display_name: string;
  phone: string;
  role: MerchantRole | null;
  role_title: string | null;
  status: MembershipStatus | null;
  employment_type: string;
  employment_status: string;
  primary_branch_id: string | null;
  is_active: boolean;
}

/**
 * Response of GET /api/v1/hr/permission-preview (Plan §10.3): what a target role
 * would hold by default plus the keys grantable to it via per-user override.
 */
export interface PermissionPreview {
  role: MerchantRole;
  default_grants: string[];
  grantable: string[];
}
