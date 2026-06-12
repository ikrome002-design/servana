<?php

declare(strict_types=1);

/*
 | Phase 1 smoke test (Plan §27 Phase 1): proves the application boots and the
 | liveness probe responds 200 with the expected envelope. Deliberately has no
 | database dependency so it passes on a clean checkout before Docker/Postgres
 | exist (Phase 2).
 */

it('boots the application and serves a healthy liveness probe', function (): void {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertJson([
        'status' => 'ok',
        'service' => 'servana',
    ]);
});

it('renders the application root', function (): void {
    $this->get('/')->assertOk();
});
