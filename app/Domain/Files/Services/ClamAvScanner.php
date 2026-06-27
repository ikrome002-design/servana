<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Enums\FileScanResult;
use App\Domain\Files\ScanOutcome;

/**
 * Production ClamAV scanner over the clamd INSTREAM TCP protocol (Plan §65, §73;
 * Phase 10F).
 *
 * Streams the file to clamd in bounded chunks (never loads the whole file into
 * memory), with bounded connect + read timeouts so a stuck scanner fails fast as a
 * retryable error rather than hanging the worker. Only the mapped verdict + safe
 * version/signature metadata are returned — never clamd's raw response, the file
 * bytes, or a malware payload.
 */
final class ClamAvScanner implements FileScanner
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout,
        private readonly float $readTimeout,
        private readonly int $chunkBytes,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $c */
        $c = config('files.clamav');

        return new self(
            (string) ($c['host'] ?? 'clamav'),
            (int) ($c['port'] ?? 3310),
            (float) ($c['connect_timeout'] ?? 3),
            (float) ($c['read_timeout'] ?? 30),
            (int) ($c['chunk_bytes'] ?? 8192),
        );
    }

    /** @param resource $stream */
    public function scanResource($stream): ScanOutcome
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->connectTimeout,
        );

        if ($socket === false) {
            return new ScanOutcome(FileScanResult::Error, 'clamav', errorCode: 'connect_failed');
        }

        stream_set_timeout($socket, (int) $this->readTimeout);

        [$engine, $signature] = $this->versions();

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, max(1, $this->chunkBytes));
                if ($chunk === false) {
                    return new ScanOutcome(FileScanResult::Error, 'clamav', $engine, $signature, errorCode: 'read_failed');
                }
                $length = strlen($chunk);
                if ($length === 0) {
                    break;
                }
                fwrite($socket, pack('N', $length).$chunk);
            }

            // Zero-length chunk terminates the stream.
            fwrite($socket, pack('N', 0));

            $response = (string) stream_get_contents($socket);
            $meta = stream_get_meta_data($socket);
        } finally {
            fclose($socket);
        }

        if (! empty($meta['timed_out'])) {
            return new ScanOutcome(FileScanResult::Error, 'clamav', $engine, $signature, errorCode: 'read_timeout');
        }

        return $this->map(trim($response), $engine, $signature);
    }

    /** Map clamd's response WITHOUT exposing its raw text. */
    private function map(string $response, ?string $engine, ?string $signature): ScanOutcome
    {
        if (str_contains($response, 'FOUND')) {
            // "stream: Eicar-Test-Signature FOUND" → capture only the signature name.
            $name = null;
            if (preg_match('/stream:\s*(.+?)\s+FOUND/', $response, $m) === 1) {
                $name = substr(trim($m[1]), 0, 191);
            }

            return new ScanOutcome(FileScanResult::Infected, 'clamav', $engine, $signature, malwareName: $name);
        }

        if (str_contains($response, 'OK')) {
            return new ScanOutcome(FileScanResult::Clean, 'clamav', $engine, $signature);
        }

        // Anything else (e.g. size-limit) is a safe error code, never the raw text.
        return new ScanOutcome(FileScanResult::Error, 'clamav', $engine, $signature, errorCode: 'scan_error');
    }

    /** @return array{0: ?string, 1: ?string} engine + signature versions (best effort). */
    private function versions(): array
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->connectTimeout,
        );

        if ($socket === false) {
            return [null, null];
        }

        stream_set_timeout($socket, (int) $this->readTimeout);
        fwrite($socket, "zVERSION\0");
        $version = trim((string) stream_get_contents($socket));
        fclose($socket);

        // "ClamAV 1.4.0/27000/Mon ..." → engine "ClamAV 1.4.0", signature "27000".
        $parts = explode('/', $version);

        return [
            $parts[0] !== '' ? substr($parts[0], 0, 60) : null,
            isset($parts[1]) ? substr($parts[1], 0, 60) : null,
        ];
    }
}
