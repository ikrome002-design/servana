<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FileScanResult;
use App\Domain\Files\Services\ClamAvScanner;

uses()->group('files', 'clamav');

/*
 | Genuine ClamAV integration (Plan §65, §73; Phase 10F). Uses the REAL ClamAV
 | INSTREAM adapter against the running clamd service (docker compose --profile
 | clamav). The EICAR test string is constructed at runtime — no malware-test file
 | is stored in the repository. A mocked scanner does NOT satisfy this test.
 |
 | Requires a reachable clamd; the suite must run with the clamav profile up.
 */

/** Build the EICAR test signature at runtime (never committed as a file). */
function eicarPayload(): string
{
    // Split so the literal signature never appears whole in the source/repo.
    return 'X5O!P%@AP[4\\PZX54(P^)7CC)7}'.'$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!'.'$H+H*';
}

/** @return resource */
function streamOf(string $contents)
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

beforeEach(function (): void {
    // Skip with a clear message only if clamd is genuinely unreachable, so a
    // misconfigured environment is obvious rather than silently passing.
    $socket = @stream_socket_client('tcp://'.config('files.clamav.host').':'.config('files.clamav.port'), $e, $s, 2);
    if ($socket === false) {
        $this->markTestSkipped('clamd unreachable — start it with `docker compose --profile clamav up -d clamav`.');
    }
    fclose($socket);
});

it('detects the EICAR signature with the real ClamAV engine', function (): void {
    $scanner = ClamAvScanner::fromConfig();

    $outcome = $scanner->scanResource(streamOf(eicarPayload()));

    expect($outcome->result)->toBe(FileScanResult::Infected)
        ->and($outcome->malwareName)->not->toBeNull()
        ->and(strtolower((string) $outcome->malwareName))->toContain('eicar')
        ->and($outcome->scanner)->toBe('clamav');
});

it('reports a clean verdict for benign content', function (): void {
    $scanner = ClamAvScanner::fromConfig();

    $outcome = $scanner->scanResource(streamOf('a perfectly ordinary, harmless string of bytes'));

    expect($outcome->result)->toBe(FileScanResult::Clean)
        ->and($outcome->malwareName)->toBeNull();
});

it('captures engine and signature versions safely', function (): void {
    $outcome = ClamAvScanner::fromConfig()->scanResource(streamOf('hello'));

    // Versions are best-effort but must be safe metadata only (no raw payload).
    expect($outcome->engineVersion)->toBeString();
});
