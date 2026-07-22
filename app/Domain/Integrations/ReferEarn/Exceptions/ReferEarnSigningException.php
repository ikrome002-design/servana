<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Exceptions;

use RuntimeException;

/**
 * Outbound signing failed closed (Plan §9 rule 22, §24.5; ADR-015; Phase 21R-A).
 *
 * Every message here is safe to surface in a job failure: it names the missing configuration or the
 * rejected algorithm identifier, and never the key id value, the secret, the signature, the nonce or
 * the payload (Plan §24.5 redaction list).
 */
final class ReferEarnSigningException extends RuntimeException
{
    public static function missingConfiguration(string $label): self
    {
        return new self("Refer & Earn {$label} is not configured; refusing to sign an outbound event.");
    }

    public static function unsupportedAlgorithm(string $algorithm): self
    {
        // The identifier itself is a public contract label, not a secret.
        return new self("Refer & Earn signing algorithm '{$algorithm}' is not supported; refusing to sign.");
    }

    public static function contentHashMismatch(string $eventId): self
    {
        return new self("Outbound event {$eventId} body hash does not match its stored content_sha256; refusing to sign.");
    }
}
