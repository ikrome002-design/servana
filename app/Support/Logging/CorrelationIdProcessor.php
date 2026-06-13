<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\CorrelationId;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds the request correlation id and environment to every log record so logs
 * can be traced end-to-end (Plan §22.1).
 */
final class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: array_merge($record->extra, [
            'correlation_id' => $this->correlationId->get(),
            'env' => (string) config('app.env'),
        ]));
    }
}
