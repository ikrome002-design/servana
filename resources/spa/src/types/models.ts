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
