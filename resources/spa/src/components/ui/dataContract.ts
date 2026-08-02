/**
 * The shared data contract for `SvDataTable` and `SvResponsiveRecordList` (Phase UI-04;
 * UI/UX plan §13.3, §13.6).
 *
 * ONE column definition drives both presentations. That is the whole point: the plan requires a
 * table on desktop and labelled record cards on mobile, and if each needed its own mapping then
 * every screen would define the same data twice and the two would drift — which is precisely how
 * "a column is missing on mobile" defects appear.
 *
 * `priority` is what makes the transformation safe. It declares importance ONCE, and both
 * presentations honour it: the tablet table may condense low-priority columns, and the mobile card
 * moves them behind an accessible detail disclosure rather than dropping them. A hidden column is
 * never silently lost.
 */

/**
 * How important a column is. Drives condensation and mobile disclosure — never deletion.
 *
 *  - `primary`   — the record's identity. Always visible, in every presentation.
 *  - `secondary` — needed to act on the row. Visible on the card face.
 *  - `detail`    — useful but not needed at a glance. Behind the card's detail disclosure.
 */
export type SvColumnPriority = 'primary' | 'secondary' | 'detail';

/** How a cell's value is aligned and rendered. `numeric` gets tabular figures and right alignment. */
export type SvColumnAlign = 'start' | 'end' | 'numeric';

export interface SvColumn<TRow> {
  /** Stable key. Also the slot name for custom cell rendering. */
  key: string;
  /** Column header AND the field label on a mobile record card. Never omitted. */
  label: string;
  priority?: SvColumnPriority;
  align?: SvColumnAlign;
  /** Only sortable columns get a sort control; the rest render a plain header. */
  sortable?: boolean;
  /**
   * Plain-text value for a row. Rendering something richer (money, a badge, a link) is done with
   * the `cell:<key>` slot, so no component ever renders caller-supplied HTML.
   */
  value?: (row: TRow) => string;
}

export type SvSortDirection = 'asc' | 'desc';

export interface SvSortState {
  key: string;
  direction: SvSortDirection;
}

/** The states a data surface can be in. Each is DISTINCT and rendered by its own component. */
export type SvDataState = 'idle' | 'loading' | 'empty' | 'error' | 'forbidden';

/** `aria-sort` for a header, per the WAI-ARIA table pattern. */
export function ariaSortFor(columnKey: string, sort: SvSortState | null): 'ascending' | 'descending' | 'none' {
  if (sort === null || sort.key !== columnKey) {
    return 'none';
  }

  return sort.direction === 'asc' ? 'ascending' : 'descending';
}

/** Columns shown on the face of a mobile record card. */
export function faceColumns<TRow>(columns: SvColumn<TRow>[]): SvColumn<TRow>[] {
  return columns.filter((column) => (column.priority ?? 'secondary') !== 'detail');
}

/** Columns moved behind the card's detail disclosure — hidden, never dropped. */
export function detailColumns<TRow>(columns: SvColumn<TRow>[]): SvColumn<TRow>[] {
  return columns.filter((column) => column.priority === 'detail');
}
