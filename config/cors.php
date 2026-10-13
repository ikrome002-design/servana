<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (Phase UI-17; UI/UX plan §18.6 "Strict CORS")
|--------------------------------------------------------------------------
|
| UI17-SEC-003. Without this file Laravel applied the framework default — `allowed_origins => ['*']`
| on `api/*` — so ANY website could read every anonymous /api/v1 response from a visitor's browser
| and every preflight was answered as allowed.
|
| Servana needs no cross-origin browser access at all. Each of the eight account hosts serves its
| own SPA and calls its own `/api/v1` on the SAME origin (`resources/spa/src/services/apiClient.ts`
| uses a relative base URL), and no account host calls another host's API: a context switch is a
| top-level navigation, not a fetch. Partner traffic (Wallet, Refer & Earn) is server-to-server and
| never subject to CORS. The exact approved cross-origin set is therefore EMPTY: a foreign origin
| receives no `Access-Control-Allow-Origin`, so the browser withholds the response.
|
| Adding an origin here is a security decision that needs its own authorization. A host is never
| authorization (ADR-017), and a CORS allowance would let that host's pages read this host's data.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
