import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SvLeadRecord from '@/components/ui/SvLeadRecord.vue';
import SvMaskedIdentity from '@/components/ui/SvMaskedIdentity.vue';
import SvStatTile from '@/components/ui/SvStatTile.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';

describe('Phase UI-14 shared visual primitives', () => {
  it('SvTonalPageHeader owns the single h1 and keeps the SvPageHeader test hooks', () => {
    const wrapper = mount(SvTonalPageHeader, {
      props: { title: 'My queue', eyebrow: 'My work', description: 'Own queue only.', context: 'Westlands', tone: 'teal' },
      slots: { actions: '<a href="#">Act</a>', default: '<p>facts</p>' },
    });
    expect(wrapper.findAll('h1')).toHaveLength(1);
    expect(wrapper.get('[data-testid="sv-page-title"]').text()).toBe('My queue');
    expect(wrapper.get('[data-testid="sv-page-header"]').attributes('data-tone')).toBe('teal');
    expect(wrapper.get('[data-testid="sv-page-actions"]').text()).toBe('Act');
    expect(wrapper.text()).toContain('Westlands');
    expect(wrapper.text()).toContain('facts');
  });

  it('SvStatTile always renders its label as text and never formats the value itself', () => {
    const wrapper = mount(SvStatTile, { props: { label: 'Outstanding', hint: 'Recorded, not yet paid', tone: 'sun' }, slots: { default: 'Ksh 1.00' } });
    expect(wrapper.text()).toContain('Outstanding');
    expect(wrapper.get('[data-testid="sv-stat-value"]').text()).toBe('Ksh 1.00');
    expect(wrapper.attributes('data-tone')).toBe('sun');
  });

  it('SvMaskedIdentity announces masking and exposes no contact link or copy control', () => {
    const wrapper = mount(SvMaskedIdentity, { props: { name: 'Njeri Wambui Kamau', phoneMasked: '+2547•••••678' } });
    expect(wrapper.text()).toContain('NK');
    expect(wrapper.text()).toContain('Masked contact: +2547•••••678');
    expect(wrapper.findAll('a, button, input')).toHaveLength(0);
    expect(wrapper.html()).not.toMatch(/tel:|sms:|mailto:/);
  });

  it('SvMaskedIdentity degrades safely without a name or phone', () => {
    const wrapper = mount(SvMaskedIdentity, { props: { name: null } });
    expect(wrapper.text()).toContain('Client');
    expect(wrapper.text()).not.toContain('Masked contact');
  });

  it('SvLeadRecord leads with its deciding fact and keeps status as a separate text slot', () => {
    const wrapper = mount(SvLeadRecord, {
      props: { leadValue: '#2', leadLabel: 'Position', tone: 'green' },
      slots: { default: '<p>Haircut</p>', status: '<span>Called</span>', meta: 'meta', actions: '<a href="#">Open</a>' },
    });
    expect(wrapper.element.tagName).toBe('ARTICLE');
    expect(wrapper.text()).toContain('#2');
    expect(wrapper.text()).toContain('Position');
    expect(wrapper.text()).toContain('Called');
    expect(wrapper.attributes('data-tone')).toBe('green');
  });
});
