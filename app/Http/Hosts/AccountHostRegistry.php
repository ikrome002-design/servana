<?php

declare(strict_types=1);

namespace App\Http\Hosts;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * The eight canonical account hosts (Phase UI-02; ADR-016; UI/UX plan §4.1–§4.7).
 *
 * Backed by `config/account_hosts.php`, which is derived from the single authority
 * `config/account-hosts.json`. Reading config (rather than the JSON) is what makes the
 * registry survive `config:cache` — a cached deployment never touches the JSON.
 *
 * Lookup is EXACT allowlist membership. There is deliberately no suffix, wildcard or
 * "ends with .servana.ke" matching: an attacker-registered `evil-servana.ke` or
 * `servana.ke.attacker.com` must not resolve to an account.
 */
final class AccountHostRegistry
{
    /** @var array<string, AccountHost>|null Lazily built exact host → account map. */
    private ?array $byHost = null;

    public function __construct(private readonly Config $config) {}

    /** @return list<string> the eight canonical account keys, in configured order */
    public function accountKeys(): array
    {
        /** @var array<string, array<string, mixed>> $accounts */
        $accounts = $this->config->get('account_hosts.accounts', []);

        return array_keys($accounts);
    }

    /** @return list<string> every approved browser host, across all environments, sorted */
    public function allHosts(): array
    {
        $hosts = array_keys($this->hostMap());
        sort($hosts);

        return $hosts;
    }

    /** @return list<string> hosts approved for one environment, in canonical account order */
    public function hostsForEnvironment(string $environment): array
    {
        $hosts = [];

        /** @var array<string, array<string, mixed>> $accounts */
        $accounts = $this->config->get('account_hosts.accounts', []);
        foreach ($accounts as $account) {
            /** @var array<string, string> $accountHosts */
            $accountHosts = $account['hosts'];
            if (isset($accountHosts[$environment])) {
                $hosts[] = $accountHosts[$environment];
            }
        }

        return $hosts;
    }

    /**
     * Resolve an already-normalized host to its account, or null when it is not approved.
     *
     * Callers must pass a host that has been through {@see AccountHostResolver::normalize()};
     * this method does not re-validate syntax, it only performs the allowlist lookup.
     */
    public function findByHost(string $normalizedHost): ?AccountHost
    {
        return $this->hostMap()[$normalizedHost] ?? null;
    }

    /** @throws InvalidArgumentException when the account key is not one of the eight */
    public function get(string $accountKey): AccountHost
    {
        /** @var array<string, mixed>|null $account */
        $account = $this->config->get("account_hosts.accounts.{$accountKey}");

        if ($account === null) {
            throw new InvalidArgumentException("Unknown account key: {$accountKey}");
        }

        return $this->hydrate($account, $this->hostForAccount($accountKey, $this->environment()));
    }

    /** The canonical host this account answers on in the given (or current) environment. */
    public function hostForAccount(string $accountKey, ?string $environment = null): string
    {
        $environment ??= $this->environment();

        /** @var array<string, string>|null $hosts */
        $hosts = $this->config->get("account_hosts.accounts.{$accountKey}.hosts");

        if ($hosts === null) {
            throw new InvalidArgumentException("Unknown account key: {$accountKey}");
        }

        return $hosts[$environment] ?? $hosts['production'];
    }

    /**
     * Hosts that may reach the application for MACHINE traffic — container health probes,
     * partner webhooks, internal jobs. They are modelled separately and NEVER resolve to an
     * account context: a machine host is not a ninth account (UI/UX plan §4.7).
     *
     * @return list<string>
     */
    public function machineHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = $this->config->get('account_hosts.machine_hosts', []);

        return array_map(static fn (string $host): string => mb_strtolower(trim($host)), $hosts);
    }

    public function isMachineHost(string $normalizedHost): bool
    {
        return in_array($normalizedHost, $this->machineHosts(), true);
    }

    /**
     * The environment bucket used for host and URL selection. `testing` shares the local
     * domain so feature tests exercise the same registry the developer does.
     *
     * @return 'production'|'staging'|'testing'|'local'
     */
    public function environment(): string
    {
        return match ((string) $this->config->get('app.env')) {
            'production' => 'production',
            'staging' => 'staging',
            'testing' => 'testing',
            default => 'local',
        };
    }

    /** @return array<string, AccountHost> */
    private function hostMap(): array
    {
        if ($this->byHost !== null) {
            return $this->byHost;
        }

        $map = [];

        /** @var array<string, array<string, mixed>> $accounts */
        $accounts = $this->config->get('account_hosts.accounts', []);
        foreach ($accounts as $account) {
            /** @var array<string, string> $hosts */
            $hosts = $account['hosts'];
            foreach ($hosts as $host) {
                $map[mb_strtolower($host)] = $this->hydrate($account, mb_strtolower($host));
            }
        }

        return $this->byHost = $map;
    }

    /** @param  array<string, mixed>  $account */
    private function hydrate(array $account, string $host): AccountHost
    {
        $placement = (string) $account['navigation_placement'];

        // Narrow at the boundary rather than widening the value object. Only the Super
        // Administrator uses header navigation (ADR-020); anything else in the registry is a
        // corrupt or hand-edited config, and guessing would silently misplace an account's
        // primary navigation.
        if ($placement !== 'header' && $placement !== 'sidebar') {
            throw new InvalidArgumentException(sprintf(
                'Account %s has an invalid navigation_placement: %s',
                (string) $account['account_key'],
                $placement,
            ));
        }

        return new AccountHost(
            accountKey: (string) $account['account_key'],
            displayName: (string) $account['display_name'],
            host: $host,
            environment: $this->environment(),
            publicContentKey: (string) $account['public_content_key'],
            legalContentKey: (string) $account['legal_content_key'],
            navigationPlacement: $placement,
            routeNamePrefix: (string) $account['route_name_prefix'],
            defaultAuthenticatedRoute: (string) $account['default_authenticated_route'],
            requiresSetup: (bool) $account['requires_setup'],
            requiresMfa: (bool) $account['requires_mfa'],
            roleFamily: (string) $account['role_family'],
            selfRegistration: (bool) $account['self_registration'],
            invitationAcceptance: (bool) $account['invitation_acceptance'],
            publicCtaCategory: (string) $account['public_cta_category'],
        );
    }
}
