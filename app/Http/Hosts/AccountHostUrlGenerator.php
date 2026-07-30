<?php

declare(strict_types=1);

namespace App\Http\Hosts;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Builds absolute URLs for an account host from the allowlist (Phase UI-02; ADR-016).
 *
 * The target host is ALWAYS taken from the registry, never from the incoming request's
 * `Host` header. That is what stops a poisoned host from being reflected into a link — the
 * classic password-reset-poisoning shape, which matters here because UI-03 will build Magic
 * Link URLs on top of this service.
 *
 * UI-02 provides the foundation only. Magic Link host binding itself is UI-03 (ADR-019).
 */
final class AccountHostUrlGenerator
{
    public function __construct(
        private readonly AccountHostRegistry $registry,
        private readonly Config $config,
    ) {}

    /**
     * Absolute URL for a path on an account host.
     *
     * @param  string  $accountKey  one of the eight canonical account keys
     * @param  string  $path  a SAFE relative path beginning with `/`
     *
     * @throws InvalidArgumentException on an unknown account or an unsafe path
     */
    public function to(string $accountKey, string $path = '/', ?string $environment = null): string
    {
        if (! in_array($accountKey, $this->registry->accountKeys(), true)) {
            throw new InvalidArgumentException("Unknown account key: {$accountKey}");
        }

        $environment ??= $this->registry->environment();
        $host = $this->registry->hostForAccount($accountKey, $environment);

        return $this->scheme($environment).'://'.$host.$this->port($environment).$this->safePath($path);
    }

    /** Absolute URL of an account's authenticated home (`/dashboard` for every account). */
    public function dashboard(string $accountKey, ?string $environment = null): string
    {
        /** @var string $route */
        $route = $this->config->get(
            "account_hosts.accounts.{$accountKey}.default_authenticated_route",
            '/dashboard',
        );

        return $this->to($accountKey, $route, $environment);
    }

    /**
     * Validate a redirect target that arrived from user input.
     *
     * Returns the normalized relative path, or null when it is unsafe. A protocol-relative
     * (`//evil.test`), absolute (`https://evil.test`), backslash-smuggled or non-rooted value
     * is rejected rather than "cleaned", so no caller can accidentally keep using it.
     */
    public function safeRelativePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $candidate = trim($path);

        if ($candidate === '' || ! str_starts_with($candidate, '/')) {
            return null;
        }
        // `//host` is protocol-relative and `/\host` is treated as such by some parsers.
        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return null;
        }
        if (str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }
        // A scheme anywhere in a value that claims to be a path is an absolute URL smuggled
        // past the leading-slash check.
        if (preg_match('#^/+[a-z][a-z0-9+.-]*://#i', $candidate) === 1) {
            return null;
        }

        return $candidate;
    }

    private function safePath(string $path): string
    {
        $safe = $this->safeRelativePath($path);

        if ($safe === null) {
            throw new InvalidArgumentException('Unsafe redirect path rejected: '.$path);
        }

        return $safe;
    }

    private function scheme(string $environment): string
    {
        return match ($environment) {
            'production' => (string) $this->config->get('account_hosts.url.production_scheme', 'https'),
            'staging' => (string) $this->config->get('account_hosts.url.staging_scheme', 'https'),
            default => (string) $this->config->get('account_hosts.url.local_scheme', 'http'),
        };
    }

    /** Local development is published on a container port; production and staging are not. */
    private function port(string $environment): string
    {
        if ($environment === 'production' || $environment === 'staging') {
            return '';
        }

        $port = $this->config->get('account_hosts.url.local_port');

        return ($port === null || $port === '' || (int) $port === 0) ? '' : ':'.(int) $port;
    }
}
