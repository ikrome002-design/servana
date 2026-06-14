export type UserStatus = 'active' | 'suspended' | 'deactivated';

export type MerchantStatus = 'active' | 'suspended' | 'deactivated' | 'pending_setup';

export type MerchantRole =
  | 'merchant_admin'
  | 'branch_manager'
  | 'hr'
  | 'finance'
  | 'front_office'
  | 'personnel'
  | 'audit';

export type Theme = 'light' | 'dark';
