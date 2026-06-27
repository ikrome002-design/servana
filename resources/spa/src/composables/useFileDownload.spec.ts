import { describe, expect, it, vi } from 'vitest';
import { requestPrivateDownload } from './useFileDownload';

describe('requestPrivateDownload', () => {
  it('issues a signed link then navigates to it, persisting nothing', async () => {
    const issueLink = vi.fn().mockResolvedValue({ url: 'https://app/api/v1/files/abc/download?sig=x', expires_at: '2026-01-01T00:00:00Z' });
    const open = vi.fn();
    const setItem = vi.spyOn(Storage.prototype, 'setItem');

    const link = await requestPrivateDownload('abc', issueLink, open);

    expect(issueLink).toHaveBeenCalledWith('abc');
    expect(open).toHaveBeenCalledWith(link.url);
    // The signed URL is never written to local/session storage.
    expect(setItem).not.toHaveBeenCalled();
    setItem.mockRestore();
  });

  it('propagates an issuance failure (no navigation)', async () => {
    const issueLink = vi.fn().mockRejectedValue(new Error('403'));
    const open = vi.fn();

    await expect(requestPrivateDownload('abc', issueLink, open)).rejects.toThrow('403');
    expect(open).not.toHaveBeenCalled();
  });
});
