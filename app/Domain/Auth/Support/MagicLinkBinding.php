<?php

declare(strict_types=1);

namespace App\Domain\Auth\Support;

use App\Domain\Auth\Services\MagicLinkTokenService;

/**
 * Everything a Magic Link is bound to at issue time (ADR-019; UI/UX plan §5.1).
 *
 * A value object rather than seven positional arguments, because every field here is a security
 * binding: adding one must be a visible, typed change, not a parameter that a caller can forget.
 *
 * `$redirectPath` has already passed `AccountHostUrlGenerator::safeRelativePath()` before it
 * reaches this object — an unsafe value is rejected at the boundary, never carried and cleaned.
 */
final readonly class MagicLinkBinding
{
    public function __construct(
        public string $email,
        public int $userId,
        public string $accountKey,
        public string $host,
        public string $environment,
        public ?string $redirectPath = null,
        public string $audience = MagicLinkTokenService::AUDIENCE_BROWSER_LOGIN,
    ) {}
}
