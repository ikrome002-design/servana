import { writeFileSync } from 'node:fs';
import type { Page, PageScreenshotOptions } from '@playwright/test';

const MAX_WRITE_ATTEMPTS = 5;
const RETRYABLE_OPEN_CODES = new Set(['EACCES', 'EBUSY', 'EPERM', 'UNKNOWN']);

function isRetryableOpenError(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false;
  }

  const code = 'code' in error && typeof error.code === 'string' ? error.code : '';

  return RETRYABLE_OPEN_CODES.has(code) && error.message.includes('open');
}

/**
 * Captures in browser memory, then persists with a bounded retry for transient
 * Windows file-open contention from virus scanners and indexers.
 */
export async function writeEvidenceScreenshot(
  page: Page,
  path: string,
  options: Omit<PageScreenshotOptions, 'path'> = {},
): Promise<Buffer> {
  const buffer = await page.screenshot(options);

  await writeEvidenceFile(path, buffer);

  return buffer;
}

/** Persists a generated evidence artifact with the same bounded open retry. */
export async function writeEvidenceFile(path: string, data: string | Uint8Array): Promise<void> {

  for (let attempt = 1; attempt <= MAX_WRITE_ATTEMPTS; attempt += 1) {
    try {
      writeFileSync(path, data);

      return;
    } catch (error) {
      if (!isRetryableOpenError(error) || attempt === MAX_WRITE_ATTEMPTS) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, attempt * 100));
    }
  }

  throw new Error(`Unable to persist evidence artifact: ${path}`);
}
