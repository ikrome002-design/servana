/**
 * Curated navigation icon registry (Phase UI-07; UI/UX plan §9.4, ADR-020).
 *
 * Every `icon` key in the canonical navigation contract resolves here, and nowhere else.
 *
 * ## Why a map exists here but deliberately does not exist in `@/design-system/icons`
 *
 * UI-04 refused a general `<SvIcon name="x" />` because resolving an ARBITRARY string forces the
 * module to reference the whole Heroicons catalogue, which defeats tree-shaking and ships the
 * catalogue to every account host. That reasoning still holds.
 *
 * This map is different in the one way that matters: its key set is CLOSED. `NavigationIconKey`
 * is generated from the contract, so this object references exactly the icons the eight account
 * navigations use — no more. Every specifier is a static named import, so the bundler still sees
 * precisely which icons are reachable. A key that is not in the contract cannot be looked up: it
 * is a type error, not a runtime miss.
 *
 * Heroicons outline at 24 throughout (UI/UX plan §9.4). No emoji, ever.
 */
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  ArrowUturnLeftIcon,
  ArrowsRightLeftIcon,
  BanknotesIcon,
  BellIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalculatorIcon,
  CalendarDaysIcon,
  ChartBarIcon,
  ChatBubbleLeftRightIcon,
  CheckBadgeIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  Cog6ToothIcon,
  CurrencyDollarIcon,
  DocumentChartBarIcon,
  DocumentCheckIcon,
  DocumentTextIcon,
  EnvelopeIcon,
  ExclamationTriangleIcon,
  EyeSlashIcon,
  FingerPrintIcon,
  FlagIcon,
  GiftIcon,
  InboxStackIcon,
  MagnifyingGlassIcon,
  PaperAirplaneIcon,
  PlayCircleIcon,
  PresentationChartLineIcon,
  PuzzlePieceIcon,
  QuestionMarkCircleIcon,
  QueueListIcon,
  ReceiptPercentIcon,
  RectangleStackIcon,
  RocketLaunchIcon,
  ScaleIcon,
  ShieldCheckIcon,
  SparklesIcon,
  Squares2X2Icon,
  TagIcon,
  UserCircleIcon,
  UserGroupIcon,
  UserPlusIcon,
  UsersIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';
import type { Component } from 'vue';
import type { NavigationIconKey } from './navigationRegistry.generated';

export const NAVIGATION_ICONS: Readonly<Record<NavigationIconKey, Component>> = {
  account: UserCircleIcon,
  activity: PresentationChartLineIcon,
  appointment: CalendarDaysIcon,
  audit: ClipboardDocumentListIcon,
  availability: ClockIcon,
  billing: ReceiptPercentIcon,
  branch: BuildingOffice2Icon,
  calendar: CalendarDaysIcon,
  'cash-up': CalculatorIcon,
  client: UserGroupIcon,
  compensation: CalculatorIcon,
  dashboard: Squares2X2Icon,
  day: ClockIcon,
  dispute: ExclamationTriangleIcon,
  earnings: BanknotesIcon,
  eligibility: CheckBadgeIcon,
  export: ArrowDownTrayIcon,
  'feature-flag': FlagIcon,
  fee: ReceiptPercentIcon,
  flag: FlagIcon,
  'get-started': RocketLaunchIcon,
  history: ClockIcon,
  integration: PuzzlePieceIcon,
  integrity: FingerPrintIcon,
  invite: EnvelopeIcon,
  invoice: DocumentTextIcon,
  merchant: BuildingStorefrontIcon,
  message: ChatBubbleLeftRightIcon,
  notification: BellIcon,
  payment: BanknotesIcon,
  payout: PaperAirplaneIcon,
  period: ClipboardDocumentListIcon,
  plan: RectangleStackIcon,
  preferences: Cog6ToothIcon,
  price: TagIcon,
  privacy: EyeSlashIcon,
  promotion: GiftIcon,
  query: QuestionMarkCircleIcon,
  queue: QueueListIcon,
  receipt: DocumentCheckIcon,
  reconciliation: ScaleIcon,
  recovery: ArrowPathIcon,
  refund: ArrowUturnLeftIcon,
  registration: UserPlusIcon,
  report: ChartBarIcon,
  salary: CurrencyDollarIcon,
  search: MagnifyingGlassIcon,
  security: ShieldCheckIcon,
  service: SparklesIcon,
  session: PlayCircleIcon,
  setup: WrenchScrewdriverIcon,
  staff: UsersIcon,
  statement: DocumentChartBarIcon,
  subscription: RectangleStackIcon,
  task: InboxStackIcon,
  transfer: ArrowsRightLeftIcon,
};

export function navigationIcon(key: NavigationIconKey): Component {
  return NAVIGATION_ICONS[key];
}
