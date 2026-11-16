<?php

namespace Tests\Support;

use App\Logging\RedactSensitiveData;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class LogCapture
{
    /**
     * Routes the default log channel to an in-memory handler that uses the production redaction processor.
     */
    public static function start(): TestHandler
    {
        $handler = new TestHandler();

        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => [
                'driver' => 'monolog',
                'handler' => TestHandler::class,
                'processors' => [RedactSensitiveData::class],
            ],
        ]);

        app()->bind(TestHandler::class, fn (): TestHandler => $handler);

        return $handler;
    }

    /**
     * @return list<LogRecord>
     */
    public static function errors(TestHandler $handler): array
    {
        return array_values(array_filter(
            $handler->getRecords(),
            fn (LogRecord $record): bool => $record->level->value >= Level::Error->value,
        ));
    }
}
