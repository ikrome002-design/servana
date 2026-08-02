/**
 * Kenyan phone-number normalisation PREVIEW (Phase UI-04; Plan §35).
 *
 * This mirrors `App\Domain\Clients\Support\PhoneNumberNormalizer` so `SvPhoneInput` can SHOW a
 * user the canonical form their entry will take. It is deliberately a preview and nothing more:
 *
 *  - it never rewrites the field as the user types;
 *  - it never decides validity — the server does, and its answer is the one that counts;
 *  - `PhoneNormalizationParityTest` asserts this implementation and the PHP one agree on a shared
 *    table of inputs, so the preview cannot quietly diverge from what will actually be stored.
 *
 * Accepts `07XXXXXXXX`, `01XXXXXXXX`, `+2547XXXXXXXX`, `2547XXXXXXXX`, `7XXXXXXXX` and produces
 * canonical E.164. Other already-international inputs are preserved digit-normalised, so the
 * platform is not Kenya-only.
 */

/** The canonical form, or null when the input carries no usable digits. Never throws. */
export function previewNormalizedPhone(raw: string): string | null {
  const hasPlus = raw.trimStart().startsWith('+');
  const digits = raw.replace(/\D+/g, '');

  if (digits === '') {
    // No digits: there is nothing to preview. The component shows nothing rather than inventing
    // a number — "do not fabricate a valid phone".
    return null;
  }

  if (hasPlus) {
    return `+${digits}`;
  }

  if (digits.startsWith('254')) {
    return `+${digits}`;
  }

  if (digits.startsWith('0') && digits.length === 10) {
    return `+254${digits.slice(1)}`;
  }

  if (digits.length === 9 && (digits.startsWith('7') || digits.startsWith('1'))) {
    return `+254${digits}`;
  }

  // Fallback: already-international digits, with no country assumption.
  return `+${digits}`;
}

/** Last four digits of a normalised number, for masked display. Mirrors the PHP helper. */
export function phoneLastFour(normalized: string): string {
  const digits = normalized.replace(/\D+/g, '');

  return digits.padStart(4, '0').slice(-4);
}
