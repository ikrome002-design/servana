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
 * Safe MFA state (Plan §18, Phase R3). UX-only routing signals — never the
 * secret or recovery-code hashes. The API is the security boundary.
 */
export interface MfaState {
  required: boolean;
  enrolled: boolean;
  confirmed: boolean;
  verified: boolean;
  enrollment_required: boolean;
  challenge_required: boolean;
  step_up_fresh: boolean;
  step_up_fresh_until: string | null;
  recovery_codes_remaining: number;
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
  mfa: MfaState;
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

/**
 * REM-SCR-002B — date-specific branch calendar exception (Plan §7.2, Scope §3.3). The three
 * closure types carry no window; `modified_hours` requires both times. `(date, type)` is the row's
 * public identity — it has no ULID — and there is exactly one exception per date.
 */
export type CalendarExceptionType =
  | 'public_holiday'
  | 'special_closure'
  | 'emergency_closure'
  | 'modified_hours';

export interface BranchCalendarException {
  date: string;
  type: CalendarExceptionType;
  /** Server-derived: true for every closure type, false for `modified_hours`. */
  closes_branch: boolean;
  opens_at: string | null;
  closes_at: string | null;
  reason: string | null;
  created_at: string | null;
}

export interface BranchCalendarExceptionInput {
  date: string;
  type: CalendarExceptionType;
  opens_at?: string | null;
  closes_at?: string | null;
  reason?: string | null;
}

/**
 * REM-SCR-002A — merchant business profile (Plan §27.3 Merchant Administrator "merchant profile").
 * `country` and the nested `merchant` block are read-only context; only the fields in
 * {@link MerchantProfileUpdate} are writable, and the backend allowlist is what enforces it.
 */
export interface MerchantProfile {
  id: string;
  business_category: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  receipt_display_name: string | null;
  address: string | null;
  town: string | null;
  timezone: string;
  country: string;
  merchant: {
    id: string;
    name: string;
    slug: string;
    status: string;
    service_fee_tier: string | null;
  } | null;
  /** Current logo: public file id + safe filename only. Never a path, URL or signature. */
  logo: { id: string; filename: string } | null;
}

export interface MerchantProfileUpdate {
  business_category?: string;
  contact_email?: string | null;
  contact_phone?: string;
  receipt_display_name?: string | null;
  address?: string | null;
  town?: string | null;
  timezone?: string;
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
 * Response row of GET /api/v1/branch/personnel-options (Plan §80 Phase 15B screen,
 * Phase 23 §14.1 security remediation). The MINIMAL branch-scoped personnel option the
 * Branch Manager's read-only schedule picker needs.
 *
 * Deliberately NOT a `StaffProfile`: the HR roster carries `phone`, `role`, `status`,
 * `employment_*` and `primary_branch_id`, and is authorized by the HR-only `staff.view`.
 * This screen must never receive personnel contact data (Plan §9.1; RK-05).
 */
export interface BranchPersonnelOption {
  id: string;
  display_name: string;
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

// --- Catalogue & clients (Plan §35, §39; Phase 15A) --------------------------

export type ServiceStatus = 'active' | 'archived';

export interface ServiceCategory {
  id: string;
  name: string;
  sort_order: number;
  archived: boolean;
  services_count?: number;
  can?: { view: boolean; update: boolean };
}

export interface Service {
  id: string;
  category_id?: string | null;
  category_name?: string | null;
  name: string;
  description: string | null;
  price_minor: number;
  currency: string;
  duration_minutes: number;
  status: ServiceStatus;
  branch_id?: string | null;
  can?: { view: boolean; update: boolean; archive: boolean };
}

export interface ServiceEligibility {
  service_id?: string | null;
  service_name?: string | null;
  staff_profile_id?: string | null;
  staff_name?: string | null;
  active: boolean;
}

// --- Appointments (Plan §36, §25.2; Phase 16A) -------------------------------

export type AppointmentStatus =
  | 'scheduled'
  | 'confirmed'
  | 'checked_in'
  | 'rescheduled'
  | 'cancelled'
  | 'cancelled_with_reason'
  | 'no_show';

export interface AppointmentPersonnelSummary {
  id: string;
  display_name: string;
}

export interface AppointmentServiceSummary {
  id: string;
  name: string;
  duration_minutes: number;
}

export interface AppointmentClientSummary {
  id: string;
  full_name: string;
  phone_masked: string;
  phone_last_four: string;
}

/** Server-derived capability map (UX only; the API re-checks every mutation). */
export interface AppointmentCapabilities {
  view: boolean;
  assign: boolean;
  transfer: boolean;
  reschedule: boolean;
  check_in: boolean;
  cancel: boolean;
  mark_no_show: boolean;
}

/** Appointment — client contact is ALWAYS masked (Plan §36; guardrail §6.4). */
export interface Appointment {
  id: string;
  status: AppointmentStatus;
  starts_at: string;
  ends_at: string;
  checked_in_at: string | null;
  cancelled_at: string | null;
  no_show_at: string | null;
  cancellation_reason: string | null;
  service?: AppointmentServiceSummary;
  client?: AppointmentClientSummary;
  preferred_personnel?: AppointmentPersonnelSummary | null;
  assigned_personnel?: AppointmentPersonnelSummary | null;
  can?: AppointmentCapabilities;
}

/** Personnel own-scope appointment (minimal, read-only). */
export interface PersonnelAppointment {
  id: string;
  status: AppointmentStatus;
  starts_at: string;
  ends_at: string;
  service?: AppointmentServiceSummary;
  client?: AppointmentClientSummary;
}

// --- Walk-ins & queues (Plan §37, §25.2; Phase 16B) --------------------------

export type QueueEntryStatus =
  | 'waiting'
  | 'assigned'
  | 'called'
  | 'in_service'
  | 'completed'
  | 'transferred'
  | 'cancelled'
  | 'no_show';

export type QueueAssignmentMode = 'next_available' | 'manual' | 'preferred_personnel';

export interface QueueEstimate {
  label: string;
  minutes: number;
  override_minutes: number | null;
  override_reason: string | null;
  effective_minutes: number;
}

/** Server-derived capability map (UX only; the API re-checks every mutation). */
export interface QueueEntryCapabilities {
  view: boolean;
  assign: boolean;
  call: boolean;
  start: boolean;
  complete: boolean;
  transfer: boolean;
  cancel: boolean;
  no_show: boolean;
}

export interface QueueEntrySource {
  type: 'walk_in' | 'appointment';
  id: string;
}

/** Queue entry — client contact is ALWAYS masked (Plan §37; guardrail §6.4). */
export interface QueueEntry {
  id: string;
  status: QueueEntryStatus;
  position: number;
  assignment_mode: QueueAssignmentMode;
  source: QueueEntrySource | null;
  queued_at: string;
  assigned_at: string | null;
  called_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  no_show_at: string | null;
  transferred_at: string | null;
  cancellation_reason: string | null;
  transfer_reason: string | null;
  preferred_personnel_override_reason: string | null;
  estimated_wait: QueueEstimate;
  service?: AppointmentServiceSummary;
  client?: AppointmentClientSummary;
  assigned_personnel?: AppointmentPersonnelSummary | null;
  preferred_personnel?: AppointmentPersonnelSummary | null;
  can?: QueueEntryCapabilities;
  /** Phase 16C: the coupled service session (present on start/complete responses). */
  service_session?: ServiceSession | null;
}

/** Service Session lifecycle (Plan §25.2; Phase 16C). */
export type ServiceSessionStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';

/** Commission preview status (Phase 16C; never earned or payable). */
export type CommissionPreviewStatus = 'available' | 'not_applicable' | 'not_configured' | 'unavailable';

/** Non-payable commission preview at completion — "Preview — not earned or payable". */
export interface CommissionPreview {
  preview_status: CommissionPreviewStatus;
  reason: string | null;
  earned: boolean;
  payable: boolean;
  amount_minor: number | null;
  currency: string | null;
}

/** Server-derived service-session capability map (UX only; the API re-checks). */
export interface ServiceSessionCapabilities {
  view: boolean;
  complete: boolean;
  cancel: boolean;
  update_notes: boolean;
}

/** Service session — client contact is ALWAYS masked (Plan §25.2; guardrail §6.4). */
export interface ServiceSession {
  id: string;
  status: ServiceSessionStatus;
  queue_entry_id?: string | null;
  started_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  notes: string | null;
  preferred_personnel_honored: boolean | null;
  service?: AppointmentServiceSummary;
  client?: AppointmentClientSummary;
  personnel?: AppointmentPersonnelSummary | null;
  commission_preview: CommissionPreview | null;
  can?: ServiceSessionCapabilities;
}

/** Personnel own-scope service session (minimal, read-only; no preview). */
export interface PersonnelServiceSession {
  id: string;
  status: ServiceSessionStatus;
  started_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  service?: AppointmentServiceSummary;
  client?: { id: string; full_name: string; phone_masked: string };
}

/** Personnel own-scope queue entry (minimal, read-only). */
export interface PersonnelQueueEntry {
  id: string;
  status: QueueEntryStatus;
  position: number;
  queued_at: string;
  estimated_wait: { label: string; effective_minutes: number };
  is_preferred_request: boolean;
  service?: AppointmentServiceSummary;
  client?: { id: string; full_name: string; phone_masked: string };
}

/** Branch queue operational configuration (on the Branch Day aggregate). */
export interface QueueConfiguration {
  branch_day_id: string | null;
  business_date: string;
  day_status: string;
  queue_is_open: boolean;
  effective_queue_open: boolean;
  queue_capacity: number | null;
  queue_default_assignment_mode: 'next_available' | 'manual';
  active_count: number;
}

export type SmsConsentState = 'opted_in' | 'opted_out';

/** Client record — contact is ALWAYS masked (Plan §35; guardrail §6.4). */
export interface Client {
  id: string;
  full_name: string;
  phone_masked: string;
  phone_last_four: string;
  email_masked: string | null;
  has_email: boolean;
  notes: string | null;
  status: 'active' | 'archived';
  sms_consent?: SmsConsentState | null;
  can?: { view: boolean; update: boolean };
}

// --- Invoicing (Plan §40, §25.3; Phase 17) ---

/** Money as the API serializes it (integer minor units + currency + formatted). */
export interface Money {
  amount: number;
  currency: string;
  formatted: string;
}

export type InvoiceStatus =
  | 'draft'
  | 'issued'
  | 'partially_paid'
  | 'paid'
  | 'void_pending'
  | 'voided'
  | 'adjusted'
  | 'refund_pending'
  | 'adjustment_required';

/** A snapshotted invoice line item sourced from a completed service session. */
export interface InvoiceItem {
  id: string;
  service_session_id?: string | null;
  service?: { id: string; name: string };
  personnel?: { id: string; display_name: string } | null;
  description: string;
  quantity: number;
  unit_price: Money;
  line_total: Money;
  preferred_personnel_fee: Money | null;
  eligible_for_commission: boolean;
  currency: string;
}

/** State-aware capability map from the backend policy (UX only). */
export interface InvoiceCapabilities {
  update: boolean;
  finalize: boolean;
  void: boolean;
  void_execute: boolean;
  void_reject: boolean;
  adjust: boolean;
}

/** Merchant-client invoice — client contact is ALWAYS masked. */
export interface Invoice {
  id: string;
  invoice_number: string | null;
  status: InvoiceStatus;
  is_draft: boolean;
  currency: string;
  client?: { id: string; full_name: string; phone_masked: string; phone_last_four: string };
  subtotal: Money;
  discount: Money;
  tax: Money;
  preferred_personnel_fee: Money | null;
  // Phase 20E — client-facing platform-fee line (shared / business-centric tiers); null when the tier
  // shifts nothing (customer-centric) or the invoice is fixed-only.
  platform_fee_client_shifted: Money | null;
  total: Money;
  validated_paid: Money;
  balance: Money;
  percentage_fee_config_snapshot: unknown | null;
  finalized_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  adjusted_at: string | null;
  adjustment_reason: string | null;
  created_at: string | null;
  items?: InvoiceItem[];
  can?: InvoiceCapabilities;
}
