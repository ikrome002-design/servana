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

/*
 | Phase UI-02 changed what "the application root" means. Servana now serves eight account
 | experiences, one per host (ADR-016), so `/` is only meaningful on an APPROVED account host —
 | the bare `localhost` this test used to rely on is a machine host and is now correctly
 | refused. The assertion is therefore split rather than dropped: the root must render on an
 | approved host, and must NOT render on a non-account host.
 */
it('renders the application root on an approved account host', function (): void {
    $this->get('http://servana.test/')
        ->assertOk()
        ->assertSee('Servana by Citrus', escape: false);
});

it('does not serve the application root on a machine host', function (): void {
    $this->get('http://localhost/')->assertStatus(421);
});
