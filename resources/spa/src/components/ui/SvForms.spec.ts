import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { describe, expect, it } from 'vitest';
import SvCheckbox from '@/components/ui/SvCheckbox.vue';
import SvCombobox from '@/components/ui/SvCombobox.vue';
import SvDatePicker from '@/components/ui/SvDatePicker.vue';
import SvFilterBar from '@/components/ui/SvFilterBar.vue';
import SvFormField from '@/components/ui/SvFormField.vue';
import SvMoneyInput from '@/components/ui/SvMoneyInput.vue';
import SvPhoneInput from '@/components/ui/SvPhoneInput.vue';
import SvRadioGroup from '@/components/ui/SvRadioGroup.vue';
import SvSearchInput from '@/components/ui/SvSearchInput.vue';
import SvSelect from '@/components/ui/SvSelect.vue';
import SvTextArea from '@/components/ui/SvTextArea.vue';
import SvTextInput from '@/components/ui/SvTextInput.vue';

/**
 * Phase UI-04 — form contract (UI/UX plan §14.1, §14.2).
 *
 * The single most important property here is that ONE component owns label/help/error
 * association. Before UI-04 three inputs had three incompatible strategies, which is how a field
 * ends up announcing its error to nobody.
 */

describe('SvFormField — the single association owner', () => {
  it('binds the label to the control', () => {
    const wrapper = mount(SvFormField, {
      props: { id: 'salary', label: 'Monthly salary' },
      slots: { default: '<input id="salary">' },
    });

    expect(wrapper.get('label').attributes('for')).toBe('salary');
    expect(wrapper.get('input').attributes('id')).toBe('salary');
  });

  it('composes described-by as help first, then message', () => {
    // Reading order matters: guidance before the failure it explains.
    const wrapper = mount(SvFormField, {
      props: { id: 'f', label: 'L', help: 'Gross, before deductions.', errors: ['Required.'] },
      // A STRING slot receives no scoped props, so the control must bind them explicitly —
      // exactly as SvTextInput and friends do.
      slots: { default: (field: Record<string, unknown>) => h('input', field) },
    });

    expect(wrapper.get('input').attributes('aria-describedby')).toBe('f-help f-message');
  });

  it('omits described-by entirely when there is nothing to describe', () => {
    const wrapper = mount(SvFormField, { props: { id: 'f', label: 'L' }, slots: { default: '<input>' } });

    expect(wrapper.get('input').attributes('aria-describedby')).toBeUndefined();
  });

  it('marks the control invalid and announces the first error', () => {
    const wrapper = mount(SvFormField, {
      props: { id: 'f', label: 'L', errors: ['Must be positive.', 'Must be under 1,000,000.'] },
      slots: { default: (field: Record<string, unknown>) => h('input', field) },
    });

    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
    const message = wrapper.get('[data-testid="sv-form-field-message"]');
    expect(message.attributes('role')).toBe('alert');
    expect(message.text()).toBe('Must be positive.');
    // The rest stay visible without being announced twice.
    expect(wrapper.text()).toContain('Must be under 1,000,000.');
  });

  it('reserves the message row so a field does not jump when an error appears', () => {
    // Growth here shoves the submit button out from under the user's finger.
    const clean = mount(SvFormField, { props: { id: 'f', label: 'L' }, slots: { default: '<input>' } });

    expect(clean.get('[data-testid="sv-form-field-message"]').classes()).toContain('min-h-4');
  });

  it('keeps the required asterisk decorative and states the requirement on the control', () => {
    const wrapper = mount(SvFormField, {
      props: { id: 'f', label: 'L', required: true },
      slots: { default: (field: Record<string, unknown>) => h('input', field) },
    });

    expect(wrapper.get('label span').attributes('aria-hidden')).toBe('true');
    expect(wrapper.get('input').attributes('aria-required')).toBe('true');
  });

  it('lets an error override a declared status', () => {
    const wrapper = mount(SvFormField, {
      props: { id: 'f', label: 'L', status: 'success', errors: ['Nope.'] },
      slots: { default: '<input>' },
    });

    expect(wrapper.attributes('data-status')).toBe('error');
  });
});

describe('every text-like control routes through SvFormField', () => {
  it('SvTextInput inherits the association contract', () => {
    const wrapper = mount(SvTextInput, {
      props: { id: 'name', label: 'Name', help: 'As it appears on the ID.', errors: ['Required.'] },
    });

    expect(wrapper.get('input').attributes('aria-describedby')).toBe('name-help name-message');
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true');
  });

  it('SvTextArea inherits the association contract', () => {
    const wrapper = mount(SvTextArea, { props: { id: 'notes', label: 'Notes', errors: ['Too long.'] } });

    expect(wrapper.get('textarea').attributes('aria-describedby')).toBe('notes-message');
  });

  it('SvSelect inherits the association contract', () => {
    const wrapper = mount(SvSelect, {
      props: { id: 'role', label: 'Role', options: [{ value: 'hr', label: 'HR' }], errors: ['Pick one.'] },
    });

    expect(wrapper.get('select').attributes('aria-describedby')).toBe('role-message');
  });

  it('never uses a placeholder as the label', () => {
    const wrapper = mount(SvTextInput, { props: { id: 'n', label: 'Full name', placeholder: 'e.g. Ada' } });

    expect(wrapper.get('label').text()).toContain('Full name');
    expect(wrapper.get('input').attributes('placeholder')).toBe('e.g. Ada');
  });

  it('emits the raw value without reformatting it mid-keystroke', async () => {
    const wrapper = mount(SvTextInput, { props: { id: 'n', label: 'N' } });
    const input = wrapper.get('input');

    await input.setValue('  Ada  ');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['  Ada  ']);
  });

  it('shows the character counter only near the limit', async () => {
    const under = mount(SvTextArea, { props: { id: 't', label: 'T', maxlength: 100, modelValue: 'short' } });
    expect(under.find('[data-testid="sv-text-area-counter"]').exists()).toBe(false);

    const near = mount(SvTextArea, {
      props: { id: 't', label: 'T', maxlength: 10, modelValue: '123456789' },
    });
    expect(near.get('[data-testid="sv-text-area-counter"]').text()).toContain('9 of 10');
  });
});

describe('SvSelect', () => {
  it('is a native select, not a custom listbox', () => {
    const wrapper = mount(SvSelect, { props: { id: 's', label: 'L', options: [] } });

    expect(wrapper.find('select').exists()).toBe(true);
    expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
  });

  it('renders groups in first-appearance order and supports disabled options', () => {
    const wrapper = mount(SvSelect, {
      props: {
        id: 's',
        label: 'L',
        options: [
          { value: 'a', label: 'A', group: 'Active' },
          { value: 'b', label: 'B', group: 'Archived', disabled: true },
          { value: 'c', label: 'C', group: 'Active' },
        ],
      },
    });

    const groups = wrapper.findAll('optgroup');
    expect(groups.map((g) => g.attributes('label'))).toEqual(['Active', 'Archived']);
    expect(groups[0].findAll('option')).toHaveLength(2);
    expect(wrapper.findAll('option').find((o) => o.attributes('value') === 'b')?.attributes('disabled')).toBeDefined();
  });
});

describe('SvCombobox', () => {
  const options = [
    { value: 'nairobi', label: 'Nairobi' },
    { value: 'mombasa', label: 'Mombasa' },
    { value: 'kisumu', label: 'Kisumu', disabled: true },
  ];

  it('implements the combobox relationship on the input', () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });
    const input = wrapper.get('[data-testid="sv-combobox"]');

    expect(input.attributes('role')).toBe('combobox');
    expect(input.attributes('aria-expanded')).toBe('false');
    expect(input.attributes('aria-autocomplete')).toBe('list');
    expect(input.attributes('aria-controls')).toBe('c-listbox');
  });

  it('points aria-activedescendant at the highlighted option', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });

    await wrapper.get('[data-testid="sv-combobox"]').trigger('click');

    expect(wrapper.get('[data-testid="sv-combobox"]').attributes('aria-activedescendant')).toBe('c-option-nairobi');
  });

  it('filters locally and reports no results distinctly from loading', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });

    await wrapper.get('[data-testid="sv-combobox"]').setValue('zzz');

    expect(wrapper.find('[data-testid="sv-combobox-empty"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-combobox-loading"]').exists()).toBe(false);
  });

  it('never says "no matches" while options are still loading', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options: [], loading: true } });

    await wrapper.get('[data-testid="sv-combobox"]').trigger('click');

    expect(wrapper.find('[data-testid="sv-combobox-loading"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-combobox-empty"]').exists()).toBe(false);
  });

  it('skips a disabled option when arrowing and refuses to commit it', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });
    const input = wrapper.get('[data-testid="sv-combobox"]');

    await input.trigger('click');
    await input.trigger('keydown', { key: 'ArrowDown' });
    // nairobi -> mombasa; kisumu is disabled so the next wrap returns to nairobi.
    expect(input.attributes('aria-activedescendant')).toBe('c-option-mombasa');

    await input.trigger('keydown', { key: 'ArrowDown' });
    expect(input.attributes('aria-activedescendant')).toBe('c-option-nairobi');
  });

  it('commits with Enter and announces the selection', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });
    const input = wrapper.get('[data-testid="sv-combobox"]');

    await input.trigger('click');
    await input.trigger('keydown', { key: 'Enter' });

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['nairobi']);
    expect(wrapper.get('[data-testid="sv-combobox-announcement"]').text()).toContain('Nairobi selected');
  });

  it('closes on Escape and keeps focus in the field', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });
    const input = wrapper.get('[data-testid="sv-combobox"]');

    await input.trigger('click');
    await input.trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('[data-testid="sv-combobox-listbox"]').exists()).toBe(false);
  });

  it('emits filter so a server-driven caller can re-supply options', async () => {
    const wrapper = mount(SvCombobox, { props: { id: 'c', label: 'Branch', options } });

    await wrapper.get('[data-testid="sv-combobox"]').setValue('mom');

    expect(wrapper.emitted('filter')?.[0]).toEqual(['mom']);
  });
});

describe('SvCheckbox and SvRadioGroup', () => {
  it('SvCheckbox is a native checkbox, not a switch', () => {
    // A switch means "takes effect now"; a checkbox means "part of what I will submit".
    const wrapper = mount(SvCheckbox, { props: { id: 'c', label: 'Send a copy' } });

    expect(wrapper.get('input').attributes('type')).toBe('checkbox');
    expect(wrapper.find('[role="switch"]').exists()).toBe(false);
  });

  it('SvCheckbox sets indeterminate as a DOM property', () => {
    const wrapper = mount(SvCheckbox, { props: { id: 'c', label: 'All', indeterminate: true } });

    expect((wrapper.get('input').element as HTMLInputElement).indeterminate).toBe(true);
  });

  it('SvRadioGroup names the group with a fieldset and legend', () => {
    const wrapper = mount(SvRadioGroup, {
      props: {
        id: 'period',
        legend: 'Salary period',
        options: [
          { value: 'monthly', label: 'Monthly' },
          { value: 'weekly', label: 'Weekly' },
        ],
      },
    });

    expect(wrapper.element.tagName).toBe('FIELDSET');
    expect(wrapper.get('legend').text()).toContain('Salary period');
  });

  it('SvRadioGroup shares one name so the browser gives roving arrow keys for free', () => {
    const wrapper = mount(SvRadioGroup, {
      props: { id: 'period', legend: 'L', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] },
    });

    expect(wrapper.findAll('input').every((i) => i.attributes('name') === 'period')).toBe(true);
  });

  it('SvRadioGroup gives every option a deterministic unique id', () => {
    const wrapper = mount(SvRadioGroup, {
      props: { id: 'period', legend: 'L', options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] },
    });

    expect(wrapper.findAll('input').map((i) => i.attributes('id'))).toEqual(['period-a', 'period-b']);
  });
});

describe('SvMoneyInput', () => {
  it('emits INTEGER minor units', async () => {
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary' } });

    await wrapper.get('[data-testid="sv-money-input"]').setValue('1234.56');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([123456]);
  });

  it('rounds rather than letting float error drop a cent', async () => {
    // `parseFloat('1234.57') * 100` is 123456.99999999999 — truncation would lose a cent.
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary' } });

    await wrapper.get('[data-testid="sv-money-input"]').setValue('1234.57');

    const emitted = wrapper.emitted('update:modelValue')?.[0][0] as number;
    expect(emitted).toBe(123457);
    expect(Number.isInteger(emitted)).toBe(true);
  });

  it('emits null for an empty field, never zero', async () => {
    // "Unavailable is not zero" applies to input as much as to display.
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary', modelValue: 5000 } });

    await wrapper.get('[data-testid="sv-money-input"]').setValue('');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null]);
  });

  it('shows the currency rather than assuming it', () => {
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary', currency: 'USD' } });

    expect(wrapper.text()).toContain('USD');
  });

  it('uses tabular numerals so amounts align', () => {
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary' } });

    expect(wrapper.get('[data-testid="sv-money-input"]').classes()).toContain('sv-numeric');
  });

  it('tidies to two decimals on blur, not while typing', async () => {
    const wrapper = mount(SvMoneyInput, { props: { id: 'm', label: 'Salary' } });
    const input = wrapper.get('[data-testid="sv-money-input"]');

    await input.setValue('12.5');
    expect((input.element as HTMLInputElement).value).toBe('12.5');

    await input.trigger('blur');
    expect((input.element as HTMLInputElement).value).toBe('12.50');
  });
});

describe('SvPhoneInput', () => {
  it('never rewrites what the user typed', async () => {
    const wrapper = mount(SvPhoneInput, { props: { id: 'p', label: 'Phone', modelValue: '0712 345 678' } });

    expect((wrapper.get('[data-testid="sv-phone-input"]').element as HTMLInputElement).value)
      .toBe('0712 345 678');
  });

  it('previews the canonical form the server will store', () => {
    const wrapper = mount(SvPhoneInput, { props: { id: 'p', label: 'Phone', modelValue: '0712345678' } });

    expect(wrapper.get('[data-testid="sv-phone-preview"]').text()).toContain('+254712345678');
  });

  it('fabricates nothing when there are no digits', () => {
    const wrapper = mount(SvPhoneInput, { props: { id: 'p', label: 'Phone', modelValue: 'abc' } });

    expect(wrapper.find('[data-testid="sv-phone-preview"]').exists()).toBe(false);
  });

  it('hides the preview once it matches what the user can already see', () => {
    const wrapper = mount(SvPhoneInput, { props: { id: 'p', label: 'Phone', modelValue: '+254712345678' } });

    expect(wrapper.find('[data-testid="sv-phone-preview"]').exists()).toBe(false);
  });
});

describe('SvDatePicker', () => {
  it('uses the native date input', () => {
    const wrapper = mount(SvDatePicker, { props: { id: 'd', label: 'Effective from' } });

    expect(wrapper.get('[data-testid="sv-date-picker"]').attributes('type')).toBe('date');
  });

  it('passes the date-only value through untouched', async () => {
    // Parsing into a Date would interpret it in the browser timezone and can shift the day.
    const wrapper = mount(SvDatePicker, { props: { id: 'd', label: 'L' } });

    await wrapper.get('[data-testid="sv-date-picker"]').setValue('2026-07-31');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['2026-07-31']);
  });

  it('applies min and max as native constraints', () => {
    const wrapper = mount(SvDatePicker, {
      props: { id: 'd', label: 'L', min: '2026-07-01', max: '2026-07-31' },
    });
    const input = wrapper.get('[data-testid="sv-date-picker"]');

    expect(input.attributes('min')).toBe('2026-07-01');
    expect(input.attributes('max')).toBe('2026-07-31');
  });
});

describe('SvSearchInput', () => {
  it('is a native search field with a real label', () => {
    const wrapper = mount(SvSearchInput, { props: { id: 's', label: 'Search clients' } });

    expect(wrapper.get('[data-testid="sv-search-input"]').attributes('type')).toBe('search');
    expect(wrapper.get('label').text()).toBe('Search clients');
  });

  it('names its clear control and only offers it when there is something to clear', async () => {
    const empty = mount(SvSearchInput, { props: { id: 's', label: 'Search clients' } });
    expect(empty.find('[data-testid="sv-search-clear"]').exists()).toBe(false);

    const filled = mount(SvSearchInput, { props: { id: 's', label: 'Search clients', modelValue: 'ada' } });
    expect(filled.get('[data-testid="sv-search-clear"]').attributes('aria-label')).toBe('Clear Search clients');
  });

  it('emits search immediately when no debounce is configured', async () => {
    const wrapper = mount(SvSearchInput, { props: { id: 's', label: 'L' } });

    await wrapper.get('[data-testid="sv-search-input"]').setValue('ada');

    expect(wrapper.emitted('search')?.[0]).toEqual(['ada']);
  });

  it('clears the value and tells the caller', async () => {
    const wrapper = mount(SvSearchInput, { props: { id: 's', label: 'L', modelValue: 'ada' } });

    await wrapper.get('[data-testid="sv-search-clear"]').trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['']);
    expect(wrapper.emitted('clear')).toHaveLength(1);
  });
});

describe('SvFilterBar', () => {
  it('shows the active count as text, so a collapsed filter is still visible', () => {
    // A hidden active filter is a common source of "where did my data go".
    const wrapper = mount(SvFilterBar, { props: { activeCount: 2 } });

    expect(wrapper.get('[data-testid="sv-filter-bar-toggle"]').text()).toContain('2 active');
  });

  it('exposes the mobile disclosure relationship', async () => {
    const wrapper = mount(SvFilterBar);
    const toggle = wrapper.get('[data-testid="sv-filter-bar-toggle"]');

    expect(toggle.attributes('aria-expanded')).toBe('false');
    expect(toggle.attributes('aria-controls')).toBe('sv-filter-bar-panel');

    await toggle.trigger('click');
    expect(wrapper.get('[data-testid="sv-filter-bar-toggle"]').attributes('aria-expanded')).toBe('true');
  });

  it('offers clear-all only when something is active', () => {
    expect(mount(SvFilterBar, { props: { activeCount: 0 } }).text()).not.toContain('Clear all');
    expect(mount(SvFilterBar, { props: { activeCount: 1 } }).text()).toContain('Clear all');
  });

  it('emits clear rather than performing a query of its own', async () => {
    const wrapper = mount(SvFilterBar, { props: { activeCount: 1 } });

    await wrapper.findAll('button').filter((b) => b.text().includes('Clear all'))[0].trigger('click');

    expect(wrapper.emitted('clear')).toHaveLength(1);
  });
});
