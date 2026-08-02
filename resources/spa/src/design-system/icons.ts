/**
 * Servana icon system (Phase UI-04; ADR-021 §5; UI/UX plan §9.4).
 *
 * ONE icon library: Heroicons for Vue, pinned exactly in `package.json`. Before UI-04 the library
 * was not a dependency at all and the shell rendered literal glyphs (`☰`, `✕`, `☀`, `☾`) with the
 * design-system demo rendering emoji — that was `UI01-ASSET-001`.
 *
 * ## Import policy
 *
 * Icons are imported INDIVIDUALLY from the `24/outline` or `24/solid` subpath so the bundler can
 * tree-shake the rest of the catalogue away. This module re-exports a CURATED set under Servana
 * names using static named re-exports, which stays fully tree-shakeable — importing `SvIconClose`
 * pulls in exactly one icon, not the library.
 *
 * There is deliberately **no** runtime `name -> component` map. A `<SvIcon name="x" />` component
 * would have to reference every icon to resolve an arbitrary string, which defeats tree-shaking
 * and would ship the whole catalogue to every account host.
 *
 * ## Style policy
 *
 * - **Outline at 24** is the default for interface icons; the softly rounded Heroicons outline
 *   style matches the warm, human brand character UI/UX plan §9.1 asks for.
 * - **Solid at 24** is used only where a filled shape carries meaning that an outline cannot —
 *   an active navigation marker or a filled status dot.
 * - Stroke width is Heroicons' own (1.5 at 24), never overridden per component.
 * - Size comes from the `sv-icon*` utility classes below, never from an inline width/height.
 *
 * ## Accessibility policy
 *
 * - A DECORATIVE icon sitting beside its own text label carries `aria-hidden="true"`.
 * - An ICON-ONLY control carries an accessible name on the CONTROL (`aria-label`), not on the
 *   icon. `SvIconButton` makes that name a required prop so it cannot be forgotten.
 * - Status is never conveyed by icon shape or colour alone; every status carries text.
 */

export {
  ArrowLeftIcon as SvIconBack,
  ArrowUpIcon as SvIconArrowUp,
  ArrowDownIcon as SvIconArrowDown,
  EnvelopeIcon as SvIconEmail,
  PaperClipIcon as SvIconAttachment,
  ArrowRightOnRectangleIcon as SvIconLogout,
  ArrowTopRightOnSquareIcon as SvIconExternal,
  ArrowPathIcon as SvIconRefresh,
  ArrowsRightLeftIcon as SvIconSwitchAccount,
  ArrowUpTrayIcon as SvIconUpload,
  BellIcon as SvIconNotifications,
  CalendarDaysIcon as SvIconCalendar,
  CheckCircleIcon as SvIconSuccess,
  CheckIcon as SvIconCheck,
  ChevronDownIcon as SvIconChevronDown,
  ChevronLeftIcon as SvIconChevronLeft,
  ChevronRightIcon as SvIconChevronRight,
  ChevronUpIcon as SvIconChevronUp,
  ChevronUpDownIcon as SvIconSort,
  Cog6ToothIcon as SvIconPreferences,
  DocumentTextIcon as SvIconDocument,
  ExclamationCircleIcon as SvIconError,
  ExclamationTriangleIcon as SvIconWarning,
  FunnelIcon as SvIconFilter,
  InformationCircleIcon as SvIconInfo,
  LockClosedIcon as SvIconLocked,
  MagnifyingGlassIcon as SvIconSearch,
  MoonIcon as SvIconDarkTheme,
  NoSymbolIcon as SvIconForbidden,
  ShieldCheckIcon as SvIconSecurity,
  SignalSlashIcon as SvIconOffline,
  SunIcon as SvIconLightTheme,
  UserCircleIcon as SvIconProfile,
  XMarkIcon as SvIconClose,
  Bars3Icon as SvIconMenu,
} from '@heroicons/vue/24/outline';

export {
  CheckCircleIcon as SvIconSuccessSolid,
  ExclamationCircleIcon as SvIconErrorSolid,
} from '@heroicons/vue/24/solid';

/**
 * The shared icon size classes. Components apply one of these rather than an inline dimension,
 * so a size change is a token change and icons never disagree across the product.
 */
export const SV_ICON_SIZE = {
  /** Inline with small text (badges, table cells). */
  sm: 'h-4 w-4 shrink-0',
  /** The default: inline with body text and inside controls. */
  md: 'h-5 w-5 shrink-0',
  /** Standalone control glyphs and navigation. */
  lg: 'h-6 w-6 shrink-0',
  /** Empty/error state illustrations. */
  xl: 'h-10 w-10 shrink-0',
} as const;

export type SvIconSize = keyof typeof SV_ICON_SIZE;
