/**
 * A random RFC 4122 version-4 UUID — for Idempotency-Keys and client-side ids (LOCAL-UAT-001, LUAT-D05).
 *
 * `crypto.randomUUID()` exists only in SECURE contexts (HTTPS or localhost). On the plain-HTTP local
 * account hosts (`http://*.servana.test:8080`) it is undefined, so every caller threw: toasts crashed
 * after a successful save and every Idempotency-Key-protected financial action failed before it was
 * sent. `crypto.getRandomValues()` is available in every context and is equally cryptographically
 * strong, so it is the fallback; production (HTTPS) keeps using `randomUUID`.
 */
export function randomUuid(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40; // version 4
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80; // RFC 4122 variant
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
