<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Idempotency\ClaimResult;
use App\Domain\Idempotency\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Idempotency\Exceptions\IdempotencyKeyReusedException;
use App\Domain\Idempotency\Exceptions\InvalidIdempotencyKeyException;
use App\Domain\Idempotency\Exceptions\RequestInProgressException;
use App\Domain\Idempotency\IdempotencyStore;
use App\Domain\Idempotency\Models\IdempotencyKey;
use App\Domain\Idempotency\Support\CanonicalRequestHasher;
use App\Domain\Idempotency\Support\IdempotencyScopeResolver;
use App\Domain\Idempotency\Support\ReplayResponseSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency + replay middleware for financial_mutation routes (Plan §24.4;
 * Phase R4). Attach as the last middleware before the controller:
 *   `EnsureIdempotentRequest::class.':retriable'`  (financial; ≥30d retention)
 *   `EnsureIdempotentRequest::class`               (standard; ≥72h retention)
 *
 * Duplicate / concurrent / recoverable-retry requests produce exactly one
 * effect; a completed request replays its stored (encrypted, sanitised)
 * response. The correctness boundary is PostgreSQL ({@see IdempotencyStore}),
 * never process memory.
 */
final class EnsureIdempotentRequest
{
    public const RETENTION_STANDARD = 'standard';

    public const RETENTION_RETRIABLE = 'retriable';

    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly CanonicalRequestHasher $hasher,
        private readonly IdempotencyScopeResolver $scopes,
        private readonly ReplayResponseSanitizer $sanitizer,
    ) {}

    public function handle(Request $request, Closure $next, string $retention = self::RETENTION_STANDARD): Response
    {
        $rawKey = $this->idempotencyKey($request);

        $scope = $this->scopes->forRequest($request);
        $keyHash = hash('sha256', $rawKey);
        $requestHash = $this->hasher->forRequest($request);

        $lockTtl = (int) Config::get('servana.idempotency.lock_ttl_seconds', 30);
        $retentionSeconds = $this->retentionSeconds($retention);

        $outcome = $this->store->claim($scope, $keyHash, $requestHash, $this->meta($request), $lockTtl, $retentionSeconds);

        return match ($outcome->result) {
            ClaimResult::ConflictDifferent => throw new IdempotencyKeyReusedException,
            ClaimResult::InProgress => throw new RequestInProgressException($outcome->retryAfterSeconds ?? $lockTtl),
            ClaimResult::Replay => $this->sanitizer->toResponse(
                $outcome->row ?? throw new \LogicException('Replay outcome without a row.'),
            ),
            ClaimResult::Claimed => $this->execute(
                $request,
                $next,
                $outcome->row ?? throw new \LogicException('Claimed outcome without a row.'),
                $retentionSeconds,
            ),
        };
    }

    /** Run the domain action once, then persist a replay-safe result. */
    private function execute(Request $request, Closure $next, IdempotencyKey $row, int $retentionSeconds): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            // The action threw (server failure): release the lock and store only a
            // redacted code so an identical request can retry. Never store the
            // exception message/stack. Re-throw for normal envelope rendering.
            $this->store->fail($row, 'server_error');

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($this->isStable($status)) {
            $captured = $this->sanitizer->capture($response);
            $this->store->complete(
                $row,
                $captured['status'] ?? $status,
                $captured['headers'] ?? [],
                $captured['body'] ?? [],
                $retentionSeconds,
            );
        } else {
            // 5xx (server) or transient 4xx (408/425/429): release the lock and
            // store only a redacted code so an identical request can retry.
            $this->store->fail($row, $status >= 500 ? 'server_error' : 'transient_error');
        }

        return $response;
    }

    private function idempotencyKey(Request $request): string
    {
        $rawKey = $request->headers->get('Idempotency-Key');

        if ($rawKey === null || trim($rawKey) === '') {
            throw new IdempotencyKeyRequiredException;
        }

        $rawKey = trim($rawKey);
        $min = (int) Config::get('servana.idempotency.key_min_length', 16);
        $max = (int) Config::get('servana.idempotency.key_max_length', 255);
        $length = strlen($rawKey);

        // Printable ASCII, no whitespace/control chars, within the length bound.
        if ($length < $min || $length > $max || preg_match('/^[\x21-\x7E]+$/', $rawKey) !== 1) {
            throw new InvalidIdempotencyKeyException;
        }

        return $rawKey;
    }

    /**
     * @return array{actor_user_id: int|null, merchant_id: int|null, branch_id: int|null, route_name: string, http_method: string, request_content_type: string|null}
     */
    private function meta(Request $request): array
    {
        $contentType = $request->headers->get('Content-Type');

        return [
            ...$this->scopes->forensics($request),
            'route_name' => substr($request->route()?->getName() ?? $request->path(), 0, 191),
            'http_method' => substr($request->getMethod(), 0, 10),
            'request_content_type' => $contentType !== null ? substr($contentType, 0, 100) : null,
        ];
    }

    /** 2xx and deterministic 4xx are replayable; 5xx + transient 4xx are retryable. */
    private function isStable(int $status): bool
    {
        if ($status >= 200 && $status < 300) {
            return true;
        }

        return $status >= 400 && $status < 500 && ! in_array($status, [408, 425, 429], true);
    }

    private function retentionSeconds(string $retention): int
    {
        if ($retention === self::RETENTION_RETRIABLE) {
            return (int) Config::get('servana.idempotency.retriable_retention_days', 30) * 86400;
        }

        return (int) Config::get('servana.idempotency.retention_hours', 72) * 3600;
    }
}
