<?php

declare(strict_types=1);

use App\Domain\Tenancy\Concerns\BelongsToMerchant;
use App\Domain\Tenancy\TenantOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

uses()->group('tenancy', 'isolation');

/*
 | Route-binding tenant safety (Plan §8.2; ADR-002; R5). Any directly route-bound
 | model that is tenant-/branch-owned MUST use BelongsToMerchant so its
 | resolveRouteBinding runs inside merchant scope (foreign ULID → 404 + audit).
 | A branch-owned model could otherwise be resolved by ULID without tenant scope.
 */

/**
 * @return array<class-string<Model>> model classes bound as controller method params.
 */
function routeBoundModels(): array
{
    $models = [];

    foreach (Route::getRoutes() as $route) {
        /** @var IlluminateRoute $route */
        $class = $route->getControllerClass();
        if ($class === null || ! method_exists($class, $route->getActionMethod())) {
            continue;
        }

        $method = new ReflectionMethod($class, $route->getActionMethod());
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeName = $type->getName();
            if (is_subclass_of($typeName, Model::class)) {
                $models[$typeName] = $typeName;
            }
        }
    }

    return array_values($models);
}

it('every route-bound tenant/branch-owned model uses BelongsToMerchant', function (): void {
    $ownedTables = array_merge(TenantOwnership::BRANCH_OWNED, TenantOwnership::TENANT_OWNED);

    foreach (routeBoundModels() as $modelClass) {
        $table = (new $modelClass)->getTable();

        if (! in_array($table, $ownedTables, true)) {
            continue; // platform/user/cross-cutting bound models are out of scope here
        }

        expect(array_keys(class_uses_recursive($modelClass)))
            ->toContain(BelongsToMerchant::class);
    }
});

it('finds at least the merchant_branches binding (sanity)', function (): void {
    $tables = array_map(fn (string $c): string => (new $c)->getTable(), routeBoundModels());

    expect($tables)->toContain('merchant_branches');
});
