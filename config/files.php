<?php

declare(strict_types=1);

/*
 | File domain configuration (Plan §65; Phase 10F). Conservative, server-side
 | limits — never trust client-supplied size/MIME/filename. The per-purpose
 | policy lives in App\Domain\Files\FilePurposeRegistry.
 */

return [
    // Private disk that holds quarantine + final objects (S3/MinIO in dev/prod).
    'disk' => env('FILES_DISK', env('FILESYSTEM_DISK', 's3') === 'local' ? 's3' : env('FILESYSTEM_DISK', 's3')),

    // Object key prefixes (never exposed publicly).
    'quarantine_prefix' => env('FILES_QUARANTINE_PREFIX', 'quarantine'),
    'final_prefix' => env('FILES_FINAL_PREFIX', 'files'),

    // Default image ceiling (bytes) for uploadable image purposes.
    'image_max_bytes' => (int) env('FILES_IMAGE_MAX_BYTES', 5 * 1024 * 1024),

    // Safe image re-encoding bounds (reject larger; strips metadata on re-encode).
    'image_max_width' => (int) env('FILES_IMAGE_MAX_WIDTH', 4096),
    'image_max_height' => (int) env('FILES_IMAGE_MAX_HEIGHT', 4096),
    'image_max_pixels' => (int) env('FILES_IMAGE_MAX_PIXELS', 24_000_000),

    // Retention windows (days).
    'export_retention_days' => (int) env('FILES_EXPORT_RETENTION_DAYS', 30),
    'quarantine_retention_hours' => (int) env('FILES_QUARANTINE_RETENTION_HOURS', 24),

    // Signed download-link lifetime (minutes).
    'signed_url_ttl_minutes' => (int) env('FILES_SIGNED_URL_TTL_MINUTES', 5),

    // ClamAV (INSTREAM over TCP). Bounded timeouts so a stuck scanner fails fast.
    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'connect_timeout' => (float) env('CLAMAV_CONNECT_TIMEOUT', 3),
        'read_timeout' => (float) env('CLAMAV_READ_TIMEOUT', 30),
        // INSTREAM chunk size (bytes); clamd rejects chunks above StreamMaxLength.
        'chunk_bytes' => (int) env('CLAMAV_CHUNK_BYTES', 8192),
    ],

    // Queue for scan/finalize/cleanup jobs.
    'queue' => env('FILES_QUEUE', 'file-scanning'),
];
