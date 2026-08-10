<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Support;

use Illuminate\Support\Str;

/**
 * The invitation credential (COR-UI08-001 §11.6; Phase UI-08).
 *
 * 64 cryptographically random bytes, SHA-256 at rest. The raw value exists only in memory long
 * enough to be handed to the delivery path, and only the digest is ever persisted (Plan §3 rule 14).
 * This value object exists so that "generate" and "look up by token" can never disagree about the
 * hashing, which is exactly the kind of drift that turns a hashed credential back into a plaintext
 * one.
 */
final readonly class PlatformAccessInvitationToken
{
    private function __construct(
        public string $raw,
        public string $hash,
    ) {}

    public static function generate(): self
    {
        $raw = Str::random(64);

        return new self($raw, self::hash($raw));
    }

    /** The single hashing definition. Every lookup path must go through it. */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Mask an address for audit context: enough to correlate, never enough to disclose.
     * `alice@example.com` becomes `a***e@example.com`.
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return '***';
        }

        [$local, $domain] = $parts;

        if (mb_strlen($local) <= 2) {
            return str_repeat('*', mb_strlen($local)).'@'.$domain;
        }

        return mb_substr($local, 0, 1).'***'.mb_substr($local, -1).'@'.$domain;
    }
}
