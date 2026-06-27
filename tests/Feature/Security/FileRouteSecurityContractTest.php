<?php

declare(strict_types=1);

use App\Domain\Files\Models\UploadedFile;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('files', 'security', 'route-contract');

/*
 | The Phase 10F file routes must satisfy the Phase 10 route-security contract: the
 | mutating routes are classified tenant_mutation with their required middleware,
 | and the download route requires BOTH authentication and a valid signature.
 */

function fileRoute(string $name): ?Route
{
    return RouteFacade::getRoutes()->getByName($name);
}

it('classifies the file mutation routes as tenant_mutation with required middleware', function (): void {
    foreach (['files.store', 'files.download-link'] as $name) {
        $route = fileRoute($name);
        expect($route)->not->toBeNull();
        expect(RouteClassification::of($route))->toBe(RouteClass::TenantMutation);
        expect(RouteClassification::requiredMiddlewareMissing($route))->toBe([]);
        expect(RouteClassification::forbiddenMiddlewarePresent($route))->toBe([]);
    }
});

it('requires authentication AND a valid signature on the download route', function (): void {
    $route = fileRoute('files.download');
    expect($route)->not->toBeNull();

    $gathered = app(Router::class)->gatherRouteMiddleware($route);
    $haystack = implode("\n", array_filter($gathered, 'is_string'));

    expect($haystack)->toContain('Illuminate\\Auth\\Middleware\\Authenticate')
        ->and($haystack)->toContain('ValidateSignature');
});

it('binds files by ULID, never an internal id', function (): void {
    expect((new UploadedFile)->getRouteKeyName())->toBe('ulid');
});
