<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 | Bind the Laravel TestCase to the Feature and Unit suites so Pest tests get
 | the full application container. Per CLAUDE.md §6.13 the database-backed
 | suites run against PostgreSQL (never SQLite); Phase 1 tests are DB-less.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
 | Post to the API as a first-party SPA request. Sending an Origin from a
 | stateful domain makes Sanctum apply the session middleware (StartSession),
 | so endpoints that establish a session (Magic Link verify → login + session
 | regeneration, Plan §9.2) exercise the real stateful path under test.
 *
 * @param  array<string, mixed>  $data
 */
function postStateful(string $uri, array $data = []): TestResponse
{
    return test()
        ->withHeader('Origin', 'http://localhost')
        ->postJson($uri, $data);
}
