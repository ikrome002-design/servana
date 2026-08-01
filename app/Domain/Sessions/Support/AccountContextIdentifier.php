<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Support;

use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Mints the opaque identifier the browser uses to name a target account context
 * (Phase UI-03; UI/UX plan §10.2 "the browser may submit only a stable opaque context identifier").
 *
 * WHY AN HMAC RATHER THAN A ULID. A raw membership ULID would be a real internal identifier
 * travelling through the browser: guessable in shape, meaningful if leaked, and tempting for a
 * future caller to parse and trust. An HMAC over `APP_KEY` is unguessable, carries no structure,
 * and — critically — is useless on its own: the switch endpoint validates it by MEMBERSHIP OF THE
 * FRESHLY DERIVED CONTEXT LIST for the authenticated user, never by decoding it. A forged or
 * replayed id therefore matches nothing.
 *
 * Determinism is deliberate: the same membership yields the same id across requests, so the SPA can
 * keep a selection across a reload without the server storing per-request state.
 */
final class AccountContextIdentifier
{
    public function __construct(private readonly Config $config) {}

    public function for(
        int $userId,
        string $accountKey,
        ?int $merchantUserId = null,
        ?int $branchId = null,
    ): string {
        $key = (string) $this->config->get('app.key');

        if ($key === '') {
            // Failing closed matters: an empty key would make every id forgeable.
            throw new RuntimeException('APP_KEY must be set before account context ids can be minted.');
        }

        $payload = implode('|', [
            'account_context.v1',
            (string) $userId,
            $accountKey,
            $merchantUserId === null ? '-' : (string) $merchantUserId,
            $branchId === null ? '-' : (string) $branchId,
        ]);

        return substr(hash_hmac('sha256', $payload, $key), 0, 32);
    }
}
