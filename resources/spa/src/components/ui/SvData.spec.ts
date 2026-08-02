import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SvAuditEvent from '@/components/ui/SvAuditEvent.vue';
import SvDataTable from '@/components/ui/SvDataTable.vue';
import SvPagination from '@/components/ui/SvPagination.vue';
import SvResponsiveRecordList from '@/components/ui/SvResponsiveRecordList.vue';
import SvTimeline from '@/components/ui/SvTimeline.vue';
import { ariaSortFor, detailColumns, faceColumns, type SvColumn } from '@/components/ui/dataContract';

/**
 * Phase UI-04 — data contract (UI/UX plan §13.3, §13.5, §13.6).
 *
 * The property that carries the most weight: ONE column definition drives both the desktop table
 * and the mobile record cards, and the mobile transformation LOSES NOTHING.
 */

/**
 * Rows are typed as the generic components see them.
 *
 * A narrower interface is NOT assignable here: `SvColumn<TRow>.value` takes a row, so `SvColumn`
 * is contravariant in `TRow` and `mount()` erases the component generic. Using the component's
 * own row type keeps the spec honest rather than papering over it with a cast.
 */
type Row = Record<string, unknown>;

const ROWS: Row[] = [
  { id: 'r1', reference: 'PR-0001', amount: '1,000.00', note: 'First' },
  { id: 'r2', reference: 'PR-0002', amount: '2,500.00', note: 'Second' },
];

const COLUMNS: SvColumn<Row>[] = [
  { key: 'reference', label: 'Reference', priority: 'primary', sortable: true, value: (r) => String(r.reference) },
  { key: 'amount', label: 'Amount', priority: 'secondary', align: 'numeric', value: (r) => String(r.amount) },
  { key: 'note', label: 'Note', priority: 'detail', value: (r) => String(r.note) },
];

const base = { columns: COLUMNS, rows: ROWS, rowKey: (r: Row) => String(r.id), caption: 'Payout runs' };

describe('the shared column contract', () => {
  it('splits face and detail columns from one definition', () => {
    expect(faceColumns(COLUMNS).map((c) => c.key)).toEqual(['reference', 'amount']);
    expect(detailColumns(COLUMNS).map((c) => c.key)).toEqual(['note']);
  });

  it('reports aria-sort only for the sorted column', () => {
    expect(ariaSortFor('reference', { key: 'reference', direction: 'asc' })).toBe('ascending');
    expect(ariaSortFor('reference', { key: 'reference', direction: 'desc' })).toBe('descending');
    expect(ariaSortFor('amount', { key: 'reference', direction: 'asc' })).toBe('none');
    expect(ariaSortFor('reference', null)).toBe('none');
  });
});

describe('SvDataTable', () => {
  it('renders a semantic table with an accessible name', () => {
    const wrapper = mount(SvDataTable, { props: base });

    expect(wrapper.find('table').exists()).toBe(true);
    expect(wrapper.get('caption').text()).toBe('Payout runs');
    expect(wrapper.findAll('th[scope="col"]')).toHaveLength(3);
  });

  it('offers a sort control only on sortable columns', () => {
    const wrapper = mount(SvDataTable, { props: base });

    expect(wrapper.find('[data-testid="sv-sort-reference"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-sort-amount"]').exists()).toBe(false);
  });

  it('reflects the sort state with aria-sort', () => {
    const wrapper = mount(SvDataTable, {
      props: { ...base, sort: { key: 'reference', direction: 'desc' as const } },
    });

    expect(wrapper.findAll('th')[0].attributes('aria-sort')).toBe('descending');
    expect(wrapper.findAll('th')[1].attributes('aria-sort')).toBeUndefined();
  });

  it('emits a sort intent rather than sorting the data itself', async () => {
    const wrapper = mount(SvDataTable, { props: base });

    await wrapper.get('[data-testid="sv-sort-reference"]').trigger('click');

    expect(wrapper.emitted('sort')?.[0]).toEqual(['reference']);
  });

  it('right-aligns numeric columns with tabular figures', () => {
    const wrapper = mount(SvDataTable, { props: base });
    const amountCell = wrapper.findAll('tbody td')[1];

    expect(amountCell.classes()).toContain('sv-numeric');
    expect(amountCell.classes()).toContain('text-right');
  });

  it('renders a permission refusal as a refusal, not as an empty table', () => {
    // "No records" and "you may not see the records" are different facts.
    const wrapper = mount(SvDataTable, { props: { ...base, state: 'forbidden' as const } });

    expect(wrapper.find('[data-testid="sv-permission-state"]').exists()).toBe(true);
    expect(wrapper.find('table').exists()).toBe(false);
    expect(wrapper.find('[data-testid="sv-empty-state"]').exists()).toBe(false);
  });

  it('keeps loading, empty and error distinct', () => {
    const loading = mount(SvDataTable, { props: { ...base, rows: [], state: 'loading' as const } });
    expect(loading.find('[data-testid="sv-skeleton"]').exists()).toBe(true);

    const empty = mount(SvDataTable, { props: { ...base, rows: [], state: 'empty' as const } });
    expect(empty.find('[data-testid="sv-empty-state"]').exists()).toBe(true);

    const error = mount(SvDataTable, { props: { ...base, rows: [], state: 'error' as const } });
    expect(error.find('[data-testid="sv-error-state"]').exists()).toBe(true);
  });

  it('never scrolls horizontally unless explicitly asked to', () => {
    // A table that quietly scrolls sideways is how page-level overflow reaches production.
    const normal = mount(SvDataTable, { props: base });
    expect(normal.html()).not.toContain('overflow-x-auto');

    const scrollable = mount(SvDataTable, { props: { ...base, scrollable: true } });
    const region = scrollable.get('[role="region"]');
    expect(region.classes()).toContain('overflow-x-auto');
    // Explicitly labelled and keyboard-scrollable when it is used.
    expect(region.attributes('aria-label')).toContain('scrollable');
    expect(region.attributes('tabindex')).toBe('0');
  });

  it('uses the row key for identity, not the index', () => {
    const wrapper = mount(SvDataTable, { props: base });

    expect(wrapper.find('[data-testid="sv-data-row-r1"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-data-row-r2"]').exists()).toBe(true);
  });
});

describe('SvResponsiveRecordList', () => {
  it('keeps every value labelled', () => {
    // A bare column of numbers with the headers scrolled away is unreadable.
    const wrapper = mount(SvResponsiveRecordList, { props: base });
    const first = wrapper.get('[data-testid="sv-record-r1"]');

    expect(first.findAll('dt').map((d) => d.text())).toEqual(['Reference', 'Amount']);
    expect(first.findAll('dd').map((d) => d.text())).toEqual(['PR-0001', '1,000.00']);
  });

  it('hides detail columns behind a disclosure rather than dropping them', () => {
    const wrapper = mount(SvResponsiveRecordList, { props: base });

    expect(wrapper.text()).not.toContain('First');
    expect(wrapper.get('[data-testid="sv-record-toggle-r1"]').text()).toContain('1 more details');
  });

  it('reveals the detail columns on request, with the same labels', async () => {
    const wrapper = mount(SvResponsiveRecordList, { props: base });

    await wrapper.get('[data-testid="sv-record-toggle-r1"]').trigger('click');

    const first = wrapper.get('[data-testid="sv-record-r1"]');
    expect(first.findAll('dt').map((d) => d.text())).toEqual(['Reference', 'Amount', 'Note']);
    expect(first.text()).toContain('First');
  });

  it('exposes the disclosure state', async () => {
    const wrapper = mount(SvResponsiveRecordList, { props: base });
    const toggle = wrapper.get('[data-testid="sv-record-toggle-r1"]');

    expect(toggle.attributes('aria-expanded')).toBe('false');
    await toggle.trigger('click');
    expect(wrapper.get('[data-testid="sv-record-toggle-r1"]').attributes('aria-expanded')).toBe('true');
  });

  it('expands one card without expanding the others', async () => {
    const wrapper = mount(SvResponsiveRecordList, { props: base });

    await wrapper.get('[data-testid="sv-record-toggle-r1"]').trigger('click');

    expect(wrapper.get('[data-testid="sv-record-toggle-r2"]').attributes('aria-expanded')).toBe('false');
  });

  it('offers no disclosure when there is nothing hidden', () => {
    const wrapper = mount(SvResponsiveRecordList, {
      props: { ...base, columns: COLUMNS.filter((c) => c.priority !== 'detail') },
    });

    expect(wrapper.find('[data-testid="sv-record-toggle-r1"]').exists()).toBe(false);
  });

  it('shares the table\'s state vocabulary', () => {
    const forbidden = mount(SvResponsiveRecordList, { props: { ...base, rows: [], state: 'forbidden' as const } });
    expect(forbidden.find('[data-testid="sv-permission-state"]').exists()).toBe(true);

    const empty = mount(SvResponsiveRecordList, { props: { ...base, rows: [], state: 'empty' as const } });
    expect(empty.find('[data-testid="sv-empty-state"]').exists()).toBe(true);
  });

  it('never introduces a horizontal scroll region', () => {
    const wrapper = mount(SvResponsiveRecordList, { props: base });

    expect(wrapper.html()).not.toContain('overflow-x');
  });

  it('renders the SAME columns the table does, from one definition', () => {
    // The whole point of the shared contract: a screen defines its data once.
    const table = mount(SvDataTable, { props: base });
    const cards = mount(SvResponsiveRecordList, { props: base });

    const tableHeaders = table.findAll('th[scope="col"]').map((th) => th.text().trim());
    const cardLabels = [
      ...cards.get('[data-testid="sv-record-r1"]').findAll('dt').map((dt) => dt.text()),
      'Note', // behind the disclosure, still present in the contract
    ];

    expect(new Set(cardLabels)).toEqual(new Set(tableHeaders));
  });
});

describe('SvPagination', () => {
  it('reports only what the server supplied', () => {
    const wrapper = mount(SvPagination, {
      props: { currentPage: 2, lastPage: 5, total: 47, perPage: 10 },
    });

    expect(wrapper.get('[data-testid="sv-pagination-range"]').text()).toBe('11–20 of 47');
  });

  it('never invents a total it was not given', () => {
    const wrapper = mount(SvPagination, { props: { currentPage: 2, lastPage: 5, total: null } });

    expect(wrapper.get('[data-testid="sv-pagination-range"]').text()).toBe('Page 2 of 5');
    expect(wrapper.text()).not.toMatch(/of \d+ records/);
  });

  it('disables the boundary controls', () => {
    const first = mount(SvPagination, { props: { currentPage: 1, lastPage: 3 } });
    expect((first.get('[data-testid="sv-pagination-previous"]').element as HTMLButtonElement).disabled).toBe(true);

    const last = mount(SvPagination, { props: { currentPage: 3, lastPage: 3 } });
    expect((last.get('[data-testid="sv-pagination-next"]').element as HTMLButtonElement).disabled).toBe(true);
  });

  it('names its controls', () => {
    const wrapper = mount(SvPagination, { props: { currentPage: 2, lastPage: 3 } });

    expect(wrapper.get('[data-testid="sv-pagination-previous"]').attributes('aria-label')).toBe('Previous page');
    expect(wrapper.get('[data-testid="sv-pagination-next"]').attributes('aria-label')).toBe('Next page');
  });

  it('emits a page intent and refuses out-of-range moves', async () => {
    const wrapper = mount(SvPagination, { props: { currentPage: 1, lastPage: 3 } });

    await wrapper.get('[data-testid="sv-pagination-next"]').trigger('click');
    expect(wrapper.emitted('change')?.[0]).toEqual([2]);

    await wrapper.get('[data-testid="sv-pagination-previous"]').trigger('click');
    expect(wrapper.emitted('change')).toHaveLength(1);
  });
});

describe('SvTimeline', () => {
  const events = [
    { id: 'e1', title: 'Submitted', at: '2026-07-15T09:00:00Z', statusLabel: 'Draft', statusTone: 'neutral' as const },
    { id: 'e2', title: 'Approved', at: '2026-07-16T09:00:00Z', statusLabel: 'Approved', statusTone: 'success' as const },
  ];

  it('is an ordered list, because the sequence is the information', () => {
    const wrapper = mount(SvTimeline, { props: { events } });

    expect(wrapper.element.tagName).toBe('OL');
    expect(wrapper.findAll('li')).toHaveLength(2);
  });

  it('renders every status as text, never colour alone', () => {
    const wrapper = mount(SvTimeline, { props: { events } });

    expect(wrapper.text()).toContain('Draft');
    expect(wrapper.text()).toContain('Approved');
  });

  it('formats timestamps through SvDateTime', () => {
    const wrapper = mount(SvTimeline, { props: { events } });

    expect(wrapper.findAll('time')).toHaveLength(2);
  });

  it('preserves the supplied order and adds nothing', () => {
    const wrapper = mount(SvTimeline, { props: { events } });

    expect(wrapper.findAll('li').map((li) => li.attributes('data-testid'))).toEqual([
      'sv-timeline-e1',
      'sv-timeline-e2',
    ]);
  });
});

describe('SvAuditEvent', () => {
  it('is read-only — it emits nothing and offers no mutating control', () => {
    // The Audit account is read-only by authority boundary (CLAUDE.md guardrail 8).
    const wrapper = mount(SvAuditEvent, { props: { action: 'payout_run.approved', at: '2026-07-16T09:00:00Z' } });

    expect(Object.keys(wrapper.emitted())).toEqual([]);
    expect(wrapper.findAll('button')).toHaveLength(0);
  });

  it('renders actor, action, time and context when the server disclosed them', () => {
    const wrapper = mount(SvAuditEvent, {
      props: {
        action: 'payout_run.approved',
        actor: 'A. Wanjiru',
        at: '2026-07-16T09:00:00Z',
        context: 'Westlands branch',
      },
    });

    expect(wrapper.text()).toContain('payout_run.approved');
    expect(wrapper.text()).toContain('A. Wanjiru');
    expect(wrapper.text()).toContain('Westlands branch');
    expect(wrapper.find('time').exists()).toBe(true);
  });

  it('omits fields the server did not disclose rather than showing a placeholder', () => {
    const wrapper = mount(SvAuditEvent, { props: { action: 'a.b', at: null } });

    expect(wrapper.text()).not.toContain('Who');
    expect(wrapper.text()).not.toContain('Context');
  });

  it('puts metadata behind a native disclosure and renders it as text', () => {
    const wrapper = mount(SvAuditEvent, {
      props: { action: 'a.b', at: null, metadata: { phone: '+2547*****78', amount: 'KES 1,000.00' } },
    });

    expect(wrapper.find('details').exists()).toBe(true);
    // Already masked server-side; nothing is re-masked or re-interpreted here.
    expect(wrapper.text()).toContain('+2547*****78');
  });

  it('renders no disclosure when there is no metadata', () => {
    expect(mount(SvAuditEvent, { props: { action: 'a.b', at: null } }).find('details').exists()).toBe(false);
  });
});
