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

/**
 * Bootstrap payload returned by GET /api/v1/me (Plan §6.2, §9.1).
 * `memberships`/`permissions` are empty until Phase 6/8 populate them.
 */
export interface AuthenticatedUser {
  id: string;
  email: string;
  name: string;
  status: UserStatus;
  email_verified_at: string | null;
  memberships: Membership[];
  permissions: string[];
  is_platform_staff: boolean;
}

export interface Merchant {
  ulid: string;
  name: string;
  slug: string;
  status: MerchantStatus;
}

export interface Branch {
  ulid: string;
  name: string;
  code: string;
  status: 'active' | 'suspended' | 'archived';
}

export interface Membership {
  ulid: string;
  role: MerchantRole;
  merchant: Merchant;
  branch_ids: string[];
}
