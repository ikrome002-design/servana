<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\Redaction\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that runs every log record through the Redactor so no
 * sensitive value is ever written to a log sink (Plan §22.1).
 */
final class RedactionProcessor implements ProcessorInterface
{
    public function __construct(private readonly Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redactArray($record->context),
            extra: $this->redactor->redactArray($record->extra),
        );
    }
}
