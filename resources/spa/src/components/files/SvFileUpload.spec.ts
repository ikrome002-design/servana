import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SvFileUpload, { type UploadedFileResource } from './SvFileUpload.vue';

function fileOf(name: string, type = 'image/png', size = 1024): File {
  const blob = new Blob([new Uint8Array(size)], { type });
  return new File([blob], name, { type });
}

const quarantined: UploadedFileResource = {
  id: '01HX0000000000000000000000',
  purpose: 'merchant_logo',
  scan_status: 'pending',
  lifecycle_status: 'quarantined',
  safe_download_filename: 'logo.png',
  size_bytes: 1024,
  can: { download: false },
};

describe('SvFileUpload', () => {
  it('renders an accessible labelled input with guidance and a 44px target', () => {
    const wrapper = mount(SvFileUpload, {
      props: { purpose: 'merchant_logo', uploader: vi.fn() },
    });
    const input = wrapper.find('input[type="file"]');
    expect(wrapper.find('label').exists()).toBe(true);
    expect(input.attributes('aria-describedby')).toContain('file-hint-merchant_logo');
    expect(input.classes().join(' ')).toContain('min-h-[44px]');
    expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Max 5 MB');
  });

  it('advances to scanning after a successful upload of a quarantined file', async () => {
    const uploader = vi.fn().mockResolvedValue(quarantined);
    const wrapper = mount(SvFileUpload, { props: { purpose: 'merchant_logo', uploader } });

    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [fileOf('logo.png')] });
    await input.trigger('change');
    await new Promise((r) => setTimeout(r));

    expect(uploader).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain('Scanning');
    expect(wrapper.emitted('uploaded')).toHaveLength(1);
  });

  it('rejects an oversized file client-side without calling the uploader', async () => {
    const uploader = vi.fn();
    const wrapper = mount(SvFileUpload, {
      props: { purpose: 'merchant_logo', uploader, maxBytes: 10 },
    });

    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [fileOf('big.png', 'image/png', 1024)] });
    await input.trigger('change');

    expect(uploader).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('larger than');
    expect(wrapper.find('button').text()).toContain('Try again');
  });

  it('surfaces a server rejection as an error state', async () => {
    const uploader = vi.fn().mockRejectedValue({ response: { data: { error: { message: 'File rejected: mime_spoof.' } } } });
    const wrapper = mount(SvFileUpload, { props: { purpose: 'merchant_logo', uploader } });

    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [fileOf('x.png')] });
    await input.trigger('change');
    await new Promise((r) => setTimeout(r));

    expect(wrapper.text()).toContain('File rejected: mime_spoof.');
    expect(wrapper.emitted('rejected')).toHaveLength(1);
  });
});
