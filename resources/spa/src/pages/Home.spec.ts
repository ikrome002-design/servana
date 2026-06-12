import { mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { describe, expect, it } from 'vitest';

import Home from '@/pages/Home.vue';

// Proves the SPA test stack (Vitest + jsdom + @vue/test-utils + Pinia) works.
describe('Home', () => {
  it('renders the Servana brand name and tagline', () => {
    const wrapper = mount(Home, {
      global: { plugins: [createPinia()] },
    });

    expect(wrapper.text()).toContain('Servana by Citrus');
    expect(wrapper.text()).toContain('Serve Better. Run Smarter. Grow Steadily.');
  });
});
