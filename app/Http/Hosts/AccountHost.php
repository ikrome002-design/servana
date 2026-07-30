<?php

declare(strict_types=1);

namespace App\Http\Hosts;

/**
 * A resolved account host (Phase UI-02; ADR-016).
 *
 * This is PRESENTATION context. It records which account experience a browser request is
 * asking for — nothing more. It never carries identity, membership, tenant, branch, role,
 * permission or MFA state, and it must never be passed to a policy, gate or query scope
 * (ADR-017). Everything authorization-bearing is re-read from the database per request.
 */
final readonly class AccountHost
{
    /**
     * @param  'production'|'staging'|'local'|'testing'  $environment
     * @param  'header'|'sidebar'  $navigationPlacement
     */
    public function __construct(
        public string $accountKey,
        public string $displayName,
        public string $host,
        public string $environment,
        public string $publicContentKey,
        public string $legalContentKey,
        public string $navigationPlacement,
        public string $routeNamePrefix,
        public string $defaultAuthenticatedRoute,
        public bool $requiresSetup,
        public bool $requiresMfa,
        public string $roleFamily,
        public bool $selfRegistration,
        public bool $invitationAcceptance,
        public string $publicCtaCategory,
    ) {}

    /**
     * The payload handed to the SPA shell.
     *
     * Deliberately free of anything sensitive: no user, tenant, branch, permission, token or
     * infrastructure detail. It is safe to render into an anonymous public page.
     *
     * @return array<string, mixed>
     */
    public function toBootstrapArray(): array
    {
        return [
            'account_key' => $this->accountKey,
            'display_name' => $this->displayName,
            'host' => $this->host,
            'environment' => $this->environment,
            'public_content_key' => $this->publicContentKey,
            'legal_content_key' => $this->legalContentKey,
            'navigation_placement' => $this->navigationPlacement,
            'route_name_prefix' => $this->routeNamePrefix,
            'default_authenticated_route' => $this->defaultAuthenticatedRoute,
            'requires_setup' => $this->requiresSetup,
            'requires_mfa' => $this->requiresMfa,
            'role_family' => $this->roleFamily,
            'self_registration' => $this->selfRegistration,
            'invitation_acceptance' => $this->invitationAcceptance,
            'public_cta_category' => $this->publicCtaCategory,
        ];
    }
}
