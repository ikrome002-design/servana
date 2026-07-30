<?php

declare(strict_types=1);

namespace App\Http\Hosts;

use Illuminate\Http\Request;

/**
 * Resolves the account experience a browser request is asking for (Phase UI-02; ADR-016/017).
 *
 * The `Host` header is attacker-controlled. This resolver therefore:
 *
 *   - normalizes case and strips the port;
 *   - rejects malformed, empty, over-long and control-character-bearing values;
 *   - rejects multiple or ambiguous host values (comma-joined proxy chains);
 *   - requires EXACT allowlist membership — never a suffix or wildcard match, so
 *     `evil-servana.ke` and `servana.ke.attacker.test` both fail;
 *   - never grants anything. A resolved host selects the experience only (ADR-017).
 *
 * `X-Forwarded-Host` is honoured only through Laravel's trusted-proxy configuration
 * (`Request::getHost()` already applies it); an untrusted forwarded host is ignored by the
 * framework, and a forwarded host that disagrees with an approved host is rejected here.
 */
final class AccountHostResolver
{
    /** RFC 1035-ish upper bound; anything longer is not a real hostname. */
    private const MAX_HOST_LENGTH = 253;

    public function __construct(private readonly AccountHostRegistry $registry) {}

    /**
     * Resolve the account host for a request, or null when the host is not an approved
     * browser account host (including every machine host).
     */
    public function resolve(Request $request): ?AccountHost
    {
        $host = $this->normalize($this->rawHost($request));

        if ($host === null) {
            return null;
        }

        return $this->registry->findByHost($host);
    }

    /**
     * Normalize a raw host value, or return null when it cannot be a valid single hostname.
     *
     * Returning null (rather than a "best effort" string) is deliberate: a caller must never
     * be handed a partially-sanitised host it might then compare loosely.
     */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Control characters and CR/LF are header-injection shapes and are checked on the RAW
        // value, BEFORE any trimming. Trimming first would silently accept
        // "finance.servana.test\r\nX-Injected: 1" once the tail was stripped.
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return null;
        }

        $host = trim($raw);

        if ($host === '' || mb_strlen($host) > self::MAX_HOST_LENGTH) {
            return null;
        }

        // Multiple hosts (`a.example, b.example` from a stacked proxy, or a header-injection
        // attempt) are ambiguous. There is no safe way to pick one, so reject outright.
        if (str_contains($host, ',')) {
            return null;
        }

        // Any remaining internal whitespace is not part of a legitimate hostname.
        if (preg_match('/\s/', $host) === 1) {
            return null;
        }

        // Strip a numeric port, and reject anything else after the colon (an IPv6 literal is
        // never one of our account hosts, so rejecting it here is correct, not a limitation).
        if (str_contains($host, ':')) {
            $parts = explode(':', $host);
            if (count($parts) !== 2 || preg_match('/^\d{1,5}$/', $parts[1]) !== 1) {
                return null;
            }
            $host = $parts[0];
        }

        $host = mb_strtolower(rtrim($host, '.'));

        if ($host === '') {
            return null;
        }

        // Only LDH labels (letters, digits, hyphen) separated by dots. This rejects
        // underscores, wildcards, path fragments, userinfo (`user@host`) and unicode
        // homographs that have not been IDNA-encoded.
        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    /** True when the request arrived on a host approved for machine traffic only. */
    public function isMachineHost(Request $request): bool
    {
        $host = $this->normalize($this->rawHost($request));

        return $host !== null && $this->registry->isMachineHost($host);
    }

    /**
     * The host Laravel resolved for this request.
     *
     * `Request::getHost()` already applies the trusted-proxy rules, so a forwarded host from
     * an UNTRUSTED proxy is ignored by the framework before it reaches us. We additionally
     * reject the case where a forwarded host is present, the proxy IS trusted, and the two
     * values disagree in a way that normalizes differently — the ambiguity is not resolvable.
     */
    private function rawHost(Request $request): ?string
    {
        try {
            $host = $request->getHost();
        } catch (\Throwable) {
            // Symfony throws on a syntactically invalid host; that is a rejection, not a 500.
            return null;
        }

        $forwarded = $request->headers->get('X-Forwarded-Host');

        if ($forwarded !== null && str_contains($forwarded, ',')) {
            return null;
        }

        return $host;
    }
}
