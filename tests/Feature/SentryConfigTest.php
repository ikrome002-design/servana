<?php

declare(strict_types=1);

it('has the Sentry hub bound so exceptions can be reported', function (): void {
    expect(app()->bound('sentry'))->toBeTrue();
});

it('does not deliver to Sentry without a DSN (safe in local/CI)', function (): void {
    $client = app('sentry')->getClient();

    // Empty SENTRY_LARAVEL_DSN => no transport target => nothing is sent.
    expect($client?->getOptions()->getDsn())->toBeNull();
});
