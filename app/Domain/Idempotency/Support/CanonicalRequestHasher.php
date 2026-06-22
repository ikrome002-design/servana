<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Support;

use Illuminate\Http\Request;

/**
 * Deterministic canonical request hash (Plan §24.4 step 2; Phase R4).
 *
 * The hash covers HTTP method, route name, normalized path parameters, request
 * content type, and the recursively-canonicalised body. Volatile headers and
 * transport-only values are excluded. JSON objects that differ only in key
 * ordering hash identically; a materially different method/path/body/content
 * type hashes differently.
 */
final class CanonicalRequestHasher
{
    /** Build the hash from a live request. */
    public function forRequest(Request $request): string
    {
        return $this->hash(
            $request->getMethod(),
            $request->route()?->getName() ?? $request->path(),
            $this->pathParameters($request),
            $request->headers->get('Content-Type'),
            $this->body($request),
        );
    }

    /**
     * Build the hash from explicit components (used in unit tests).
     *
     * @param  array<string, mixed>  $pathParams
     */
    public function hash(string $method, string $routeName, array $pathParams, ?string $contentType, mixed $body): string
    {
        $canonical = [
            'method' => strtoupper($method),
            'route' => $routeName,
            'path' => $this->canonicalize($pathParams),
            'content_type' => $this->normalizeContentType($contentType),
            'body' => $this->canonicalize($body),
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Raw (pre-binding) route parameters, so the hash uses the ULID string and
     * never a hydrated model.
     *
     * @return array<string, mixed>
     */
    private function pathParameters(Request $request): array
    {
        $route = $request->route();

        return $route !== null ? $route->originalParameters() : [];
    }

    private function body(Request $request): mixed
    {
        if ($request->isJson()) {
            $decoded = json_decode((string) $request->getContent(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    /** Recursively sort associative arrays by key so ordering never affects the hash. */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        // Sort string-keyed maps by key; preserve list order (lists are significant).
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    /** Lowercase the media type and drop parameters (charset/boundary). */
    private function normalizeContentType(?string $contentType): ?string
    {
        if ($contentType === null || $contentType === '') {
            return null;
        }

        $mediaType = explode(';', $contentType, 2)[0];

        return strtolower(trim($mediaType));
    }
}
