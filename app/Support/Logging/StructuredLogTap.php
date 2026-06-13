<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Channel tap (Plan §22.1) that turns a channel into structured JSON output and
 * attaches the redaction + correlation processors. Wired via the `tap` key in
 * config/logging.php.
 */
final class StructuredLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        // The underlying driver is a Monolog Logger; guard for type-safety.
        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true));
            }
        }

        // Redaction is pushed last so it runs as the final pass over the record,
        // after correlation metadata has been added.
        $monolog->pushProcessor(app(CorrelationIdProcessor::class));
        $monolog->pushProcessor(app(RedactionProcessor::class));
    }
}
