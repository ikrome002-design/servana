<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

uses()->group('security');

/*
 | Regression for GHSA-crmm-hgp2-wgrp — "Temporary Signed URL Path Confusion"
 | (medium), fixed in laravel/framework 12.61.1 (resolved 12.62.0). Servana does
 | not yet expose any signed routes, so these tests pin the framework guarantee
 | directly via the default `signed` route middleware (ValidateSignature): a
 | correctly signed temporary URL is accepted, while a URL with a tampered query,
 | an altered path (the path-confusion vector), or an elapsed expiry is rejected
 | with 403. This proves valid signed URLs keep working AND that signature
 | validation is bound to the full path after the upgrade.
 */
beforeEach(function (): void {
    Route::get('/__sig-test/{id}', fn () => 'ok')
        ->name('test.signed')
        ->middleware('signed');

    // A name set fluently after registration is not in the collection's name
    // lookup until refreshed; without this, route-name URL generation 404s.
    app('router')->getRoutes()->refreshNameLookups();
});

it('accepts a correctly signed temporary URL', function (): void {
    $url = URL::temporarySignedRoute('test.signed', now()->addMinutes(5), ['id' => 7]);

    test()->get($url)->assertOk()->assertSee('ok');
});

it('rejects a signed URL whose query string was tampered with', function (): void {
    $url = URL::temporarySignedRoute('test.signed', now()->addMinutes(5), ['id' => 7]);

    test()->get($url.'&injected=1')->assertForbidden();
});

it('rejects a signed URL whose path was altered (path confusion)', function (): void {
    // Sign /__sig-test/7, then replay the identical signature+expiry query
    // against /__sig-test/9. Post-fix the signature is bound to the path, so
    // the swapped path must fail validation.
    $url = URL::temporarySignedRoute('test.signed', now()->addMinutes(5), ['id' => 7]);
    $query = parse_url($url, PHP_URL_QUERY);

    test()->get(url('/__sig-test/9').'?'.$query)->assertForbidden();
});

it('rejects an expired signed URL', function (): void {
    $url = URL::temporarySignedRoute('test.signed', now()->subMinute(), ['id' => 7]);

    test()->get($url)->assertForbidden();
});
