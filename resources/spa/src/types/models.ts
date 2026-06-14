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
 * endpoint (Plan §6.2, §8.1). Phase 6 fills merchant/membership/setup from the
 * resolved tenant context. `permissions` is populated by the Phase 8 registry.
 */
export interface BootstrapPayload {
  user: AuthenticatedUser;
  merchant: Merchant | null;
  membership: MerchantMembership | null;
  memberships: MerchantMembership[];
  permissions: string[];
  setup: SetupState;
}

export interface Branch {
  ulid: string;
  name: string;
  code: string;
  status: 'active' | 'suspended' | 'archived';
}
