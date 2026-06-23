<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Deterministic OpenAPI 3.1 generator for the Servana production API (Plan §23,
 * §24; Phase 10 REM-ROUTE-001).
 *
 * The endpoint inventory is DERIVED from the live route collection — it is never
 * hand-maintained. The document describes exactly the current production
 * `/api/v1/*` routes plus `/health` and `/health/deep`; test-only routes
 * (`testing.*`) and framework/web routes are excluded. Output is byte-stable
 * (sorted paths/keys, fixed JSON flags) so {@see OpenApiContractTest} can detect a
 * stale committed artifact, and the generated TypeScript types stay in parity.
 *
 * Security, the standard error envelope (§11.5), pagination/filter/sort
 * parameters, and the financial `Idempotency-Key` header are emitted from the
 * route's classification + Form Request, so the contract follows the code.
 */
final class OpenApiGenerator
{
    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];

        foreach ($this->productionRoutes() as $route) {
            $path = '/'.ltrim($route->uri(), '/');
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            sort($methods);

            foreach ($methods as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }
        unset($operations);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Servana by Citrus API',
                'version' => 'v1',
                'description' => 'Generated production API contract (Plan §23/§24). Do not edit by hand — run `composer api:openapi`.',
            ],
            'servers' => [['url' => '/']],
            'paths' => $paths,
            'components' => $this->components(),
        ];
    }

    /** Byte-stable pretty JSON (trailing newline) for the committed artifact. */
    public function toJson(): string
    {
        return json_encode(
            $this->generate(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @return list<Route> production API + health routes, test/framework excluded. */
    public function productionRoutes(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($this->isProduction($route)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function isProduction(Route $route): bool
    {
        $uri = $route->uri();
        $name = $route->getName() ?? '';

        $isApi = str_starts_with($uri, 'api/v1/') || $uri === 'health' || $uri === 'health/deep';

        if (! $isApi) {
            return false;
        }

        // Never publish test-only routes into the production inventory.
        return ! str_starts_with($name, 'testing.') && ! str_contains($uri, 'api/v1/testing/');
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $method): array
    {
        $name = $route->getName() ?? ($method.' '.$route->uri());
        $class = RouteClassification::of($route);
        $isMutation = $method !== 'GET';

        $operation = [
            'operationId' => $name,
            'tags' => [$this->tag($route)],
            'summary' => $name,
        ];

        // Security: authenticated routes use the Sanctum session cookie; public &
        // health routes explicitly carry no security.
        $operation['security'] = $this->requiresAuth($route) ? [['sanctumSession' => []]] : [];

        $parameters = $this->pathParameters($route);

        // Financial mutations require an Idempotency-Key header (§24.4).
        if ($class === RouteClass::FinancialMutation) {
            $parameters[] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 255],
            ];
        }

        $formRequest = $this->formRequestRules($route);

        if (! $isMutation && $formRequest !== null) {
            // GET collection: validated filters/sort/pagination become query params.
            foreach ($formRequest as $field => $tokens) {
                $parameters[] = [
                    'name' => $field,
                    'in' => 'query',
                    'required' => in_array('required', $tokens, true),
                    'schema' => $this->schemaForTokens($tokens),
                ];
            }
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($isMutation && $formRequest !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => $this->bodySchema($formRequest)]],
            ];
        }

        $operation['responses'] = $this->responses($route, $method, $class);

        return $operation;
    }

    /** @return array<int|string, mixed> */
    private function responses(Route $route, string $method, ?RouteClass $class): array
    {
        $responses = [];
        $successCode = str_ends_with($route->getName() ?? '', '.store') || str_ends_with($route->getName() ?? '', '.self-register')
            ? '201'
            : '200';

        $responses[$successCode] = [
            'description' => 'Success',
            'content' => ['application/json' => ['schema' => ['type' => 'object']]],
        ];

        if ($this->requiresAuth($route)) {
            $responses['401'] = $this->errorRef('Unauthenticated');
            $responses['403'] = $this->errorRef('Permission denied');
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = $this->errorRef('Not found (foreign-tenant ids 404 without existence leak)');
        }

        if ($method !== 'GET' && ($class?->requiresValidation() ?? false)) {
            $responses['422'] = $this->errorRef('Validation failed');
        }

        if ($method === 'GET' && $this->formRequestRules($route) !== null) {
            $responses['422'] = $this->errorRef('Invalid pagination/filter/sort');
        }

        if ($class === RouteClass::FinancialMutation) {
            $responses['409'] = $this->errorRef('Idempotency conflict / request in progress');
            $responses['423'] = $this->errorRef('Financial period locked');
        }

        $responses['429'] = $this->errorRef('Rate limited');

        ksort($responses);

        return $responses;
    }

    /** @return array<string, mixed> */
    private function errorRef(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pathParameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $param) {
            $parameters[] = [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'description' => 'ULID public identifier',
                'schema' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
            ];
        }

        return $parameters;
    }

    /**
     * Top-level validation rules for the route's Form Request, normalized to
     * token lists, or null when the action has no Form Request / rules cannot be
     * resolved statically.
     *
     * @return array<string, list<string>>|null
     */
    private function formRequestRules(Route $route): ?array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $requestClass = null;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                $requestClass = $type->getName();
                break;
            }
        }

        if ($requestClass === null) {
            return null;
        }

        try {
            /** @var FormRequest $instance */
            $instance = new $requestClass;
            $rules = method_exists($instance, 'rules') ? $instance->rules() : [];
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];
        foreach ($rules as $field => $ruleSet) {
            if (str_contains((string) $field, '.')) {
                continue; // nested array rules — represented by their parent
            }
            $normalized[(string) $field] = $this->ruleTokens($ruleSet);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  mixed  $ruleSet
     * @return list<string>
     */
    private function ruleTokens($ruleSet): array
    {
        if (is_string($ruleSet)) {
            return explode('|', $ruleSet);
        }

        if (is_array($ruleSet)) {
            return array_values(array_map(
                fn ($rule): string => is_string($rule) ? $rule : 'rule',
                $ruleSet,
            ));
        }

        return ['rule'];
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @return array<string, mixed>
     */
    private function bodySchema(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field => $tokens) {
            $properties[$field] = $this->schemaForTokens($tokens);
            if (in_array('required', $tokens, true)) {
                $required[] = $field;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            sort($required);
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private function schemaForTokens(array $tokens): array
    {
        foreach ($tokens as $token) {
            if ($token === 'integer') {
                return ['type' => 'integer'];
            }
            if ($token === 'boolean') {
                return ['type' => 'boolean'];
            }
            if ($token === 'array') {
                return ['type' => 'array', 'items' => ['type' => 'string']];
            }
            if ($token === 'date') {
                return ['type' => 'string', 'format' => 'date-time'];
            }
        }

        return ['type' => 'string'];
    }

    private function tag(Route $route): string
    {
        $name = $route->getName() ?? '';

        if ($name === 'health' || $name === 'health.deep') {
            return 'health';
        }

        $segment = explode('.', $name)[0];

        return $segment !== '' ? $segment : 'api';
    }

    private function requiresAuth(Route $route): bool
    {
        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && str_contains($middleware, 'Illuminate\\Auth\\Middleware\\Authenticate')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function components(): array
    {
        return [
            'schemas' => [
                'ErrorEnvelope' => [
                    'type' => 'object',
                    'description' => 'Standard API error envelope (Plan §11.5).',
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'fields' => ['type' => 'object'],
                                'meta' => ['type' => 'object'],
                            ],
                            'required' => ['code', 'message'],
                        ],
                    ],
                    'required' => ['error'],
                ],
                'PaginationMeta' => [
                    'type' => 'object',
                    'description' => 'Length-aware pagination meta (default 25, max 100).',
                    'properties' => [
                        'current_page' => ['type' => 'integer'],
                        'last_page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'total' => ['type' => 'integer'],
                    ],
                ],
            ],
            'securitySchemes' => [
                'sanctumSession' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'servana_session',
                    'description' => 'Laravel Sanctum first-party stateful session cookie + X-XSRF-TOKEN.',
                ],
            ],
        ];
    }
}
