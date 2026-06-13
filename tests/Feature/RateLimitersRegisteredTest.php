<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

it('registers every named rate limiter from Plan §9.3', function (string $name): void {
    expect(RateLimiter::limiter($name))->not->toBeNull();
})->with([
    'magic-link-request',
    'magic-link-verify',
    'registration',
    'invitation-accept',
    'api',
    'finance-sensitive',
    'search',
]);
