// KES stored as integer minor units (Plan §2 AS-3, §6).
// Display only — arithmetic must never use this output.

export function formatMoney(minorUnits: number, currency = 'KES'): string {
  const major = minorUnits / 100;
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
  }).format(major);
}

export function parseMoney(display: string): number {
  const numeric = display.replace(/[^0-9.]/g, '');
  return Math.round(parseFloat(numeric) * 100);
}
