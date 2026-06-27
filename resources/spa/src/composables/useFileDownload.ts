/**
 * Private-download helper (Plan §65; Phase 10F).
 *
 * Issues a short-lived signed link via the authorized endpoint, then navigates the
 * browser to it. The signed URL is used transiently and NEVER persisted to
 * localStorage/sessionStorage — it is a one-time, expiring, server-authorized link.
 */

export interface SignedDownloadLink {
  url: string;
  expires_at: string;
}

/** Issues `POST /api/v1/files/{id}/download-link` and returns the signed link. */
export type DownloadLinkIssuer = (fileId: string) => Promise<SignedDownloadLink>;

/** Opens a URL (default: same-tab navigation). Injected for testability. */
export type UrlOpener = (url: string) => void;

const defaultOpener: UrlOpener = (url) => {
  // Same-tab navigation to the authorized, expiring endpoint. Nothing is stored.
  if (typeof window !== 'undefined') window.location.assign(url);
};

/**
 * Request a private download: issue the signed link, then navigate to it.
 * Returns the link metadata (expiry) for optional UI display.
 */
export async function requestPrivateDownload(
  fileId: string,
  issueLink: DownloadLinkIssuer,
  open: UrlOpener = defaultOpener,
): Promise<SignedDownloadLink> {
  const link = await issueLink(fileId);
  open(link.url);
  return link;
}
