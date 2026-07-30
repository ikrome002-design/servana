<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account hosts (Phase UI-02; ADR-016, ADR-017; UI/UX plan §4.1–§4.7)
|--------------------------------------------------------------------------
|
| Eight canonical account hosts, all served by ONE Servana application. The
| authority is `config/account-hosts.json`; this file only shapes it for
| Laravel and applies environment configuration.
|
| Reading the JSON here is safe under `config:cache`: the cache stores the
| RESULT of this file, so a cached deployment never touches the JSON again
| (asserted by AccountHostConfigurationCacheTest).
|
| THE HOST IS NEVER AUTHORIZATION (ADR-017). It selects the experience —
| public content key, branding, navigation placement, route family and the
| default authenticated route. Identity, membership, tenant, branch, role,
| permission and MFA state are always re-evaluated from the database.
|
*/

$source = json_decode(
    (string) file_get_contents(__DIR__.'/account-hosts.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$productionDomain = (string) env('ACCOUNT_HOST_PRODUCTION_DOMAIN', $source['domains']['production']);
$localDomain = (string) env('ACCOUNT_HOST_LOCAL_DOMAIN', $source['domains']['local']);
$stagingSuffix = (string) env('ACCOUNT_HOST_STAGING_SUFFIX', $source['domains']['staging_suffix']);

/** Prefix a bare domain with the account subdomain, or return it unchanged for the apex account. */
$host = static fn (?string $subdomain, string $domain): string => $subdomain === null
    ? $domain
    : $subdomain.'.'.$domain;

$accounts = [];

foreach ($source['accounts'] as $account) {
    $subdomain = $account['subdomain'];

    $accounts[$account['account_key']] = [
        'account_key' => $account['account_key'],
        'display_name' => $account['display_name'],
        'subdomain' => $subdomain,

        // The three environment hosts. Staging is DERIVED from the configured
        // suffix so the suffix is never scattered as hard-coded logic.
        'hosts' => [
            'production' => $host($subdomain, $productionDomain),
            'staging' => $host($subdomain, 'servana.'.$stagingSuffix),
            'local' => $host($subdomain, $localDomain),
            'testing' => $host($subdomain, $localDomain),
        ],

        // Foundation metadata for later UI phases. None of it is authorization.
        'public_content_key' => $account['public_content_key'],
        'legal_content_key' => $account['legal_content_key'],
        'landing_image_directory' => $account['landing_image_directory'],
        'navigation_placement' => $account['navigation_placement'],
        'route_name_prefix' => $account['route_name_prefix'],
        'default_authenticated_route' => $account['default_authenticated_route'],
        'requires_setup' => $account['requires_setup'],
        'requires_mfa' => $account['requires_mfa'],
        'role_family' => $account['role_family'],
        'self_registration' => $account['self_registration'],
        'invitation_acceptance' => $account['invitation_acceptance'],
        'public_cta_category' => $account['public_cta_category'],
    ];
}

return [
    'version' => $source['version'],

    'domains' => [
        'production' => $productionDomain,
        'local' => $localDomain,
        'staging_suffix' => $stagingSuffix,
    ],

    /*
    | Scheme and port used when GENERATING absolute URLs for an account host.
    | Local development runs on a published container port, so the port is
    | configuration rather than string concatenation spread through the code.
    */
    'url' => [
        'production_scheme' => (string) env('ACCOUNT_HOST_PRODUCTION_SCHEME', 'https'),
        'staging_scheme' => (string) env('ACCOUNT_HOST_STAGING_SCHEME', 'https'),
        'local_scheme' => (string) env('ACCOUNT_HOST_LOCAL_SCHEME', 'http'),
        'local_port' => env('ACCOUNT_HOST_LOCAL_PORT', 8080),
    ],

    /*
    | Non-account hosts that may reach the application for MACHINE traffic
    | (health probes, container liveness, partner webhooks, internal jobs).
    | They are modelled SEPARATELY and never resolve to an account context —
    | a machine host is not a ninth account (UI/UX plan §4.7).
    */
    'machine_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ACCOUNT_HOST_MACHINE_HOSTS', 'localhost,127.0.0.1,app,nginx')),
    ))),

    'accounts' => $accounts,
];
