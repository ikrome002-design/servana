<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Request-scoped holder for the current correlation id. Bound as a singleton so
 * the middleware, the log processor, and the error renderer all read the same
 * value (Plan §11.5, §22.1).
 */
final class CorrelationId
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public function get(): ?string
    {
        return $this->id;
    }

    public function getOrGenerate(): string
    {
        return $this->id ??= (string) Str::ulid();
    }
}
