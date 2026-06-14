import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SvStateBoundary from './SvStateBoundary.vue';

describe('SvStateBoundary', () => {
  it('renders skeleton in loading state', () => {
    const wrapper = mount(SvStateBoundary, { props: { state: 'loading' } });
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);
    expect(wrapper.find('.animate-pulse').exists()).toBe(true);
  });

  it('renders empty message and action in empty state', async () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'empty', emptyMessage: 'No items.', emptyAction: 'Add one' },
    });
    expect(wrapper.text()).toContain('No items.');
    expect(wrapper.text()).toContain('Add one');
  });

  it('emits empty-action on button click', async () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'empty', emptyMessage: 'Empty', emptyAction: 'Go' },
    });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('empty-action')).toHaveLength(1);
  });

  it('renders error message and retry button in error state', () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'error', errorMessage: 'Something broke.' },
    });
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Something broke.');
    expect(wrapper.text()).toContain('Try again');
  });

  it('emits retry on retry click', async () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'error', errorMessage: 'Oops' },
    });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });

  it('renders slot content in success state', () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'success' },
      slots: { default: '<p>Content here</p>' },
    });
    expect(wrapper.text()).toContain('Content here');
    expect(wrapper.find('[aria-busy]').exists()).toBe(false);
  });

  it('uses default empty message when not provided', () => {
    const wrapper = mount(SvStateBoundary, { props: { state: 'empty' } });
    expect(wrapper.text()).toContain('Nothing here yet.');
  });

  it('uses default error message when not provided', () => {
    const wrapper = mount(SvStateBoundary, { props: { state: 'error' } });
    expect(wrapper.text()).toContain('Something went wrong.');
  });
});
