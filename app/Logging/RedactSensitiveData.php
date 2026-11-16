<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class RedactSensitiveData implements ProcessorInterface
{
    /**
     * Matches `"key": value` pairs in JSON-like text, including broken JSON and an unterminated final string.
     */
    private const JSON_PAIR_PATTERN = '/"((?>[^"\\\\]|\\\\.)*+)"(\s*:\s*)("(?>[^"\\\\]|\\\\.)*+(?:"|$)|[^\s,}\]"]+)/s';

    /**
     * @var list<string>
     */
    private array $keys;

    private string $replacement;

    private int $maxStringLength;

    /**
     * @param  list<string>|null  $keys  Normalized fragments; defaults to config('logging.redact.keys').
     */
    public function __construct(?array $keys = null, ?string $replacement = null, ?int $maxStringLength = null)
    {
        $this->keys = array_map(
            fn (string $key): string => $this->normalizeKey($key),
            $keys ?? config('logging.redact.keys', []),
        );
        $this->replacement = $replacement ?? (string) config('logging.redact.replacement', '[REDACTED]');
        $this->maxStringLength = $maxStringLength ?? (int) config('logging.redact.max_string_length', 10000);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = $this->replacement;

                continue;
            }

            $data[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->processString($value),
                default => $value,
            };
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        foreach ($this->keys as $pattern) {
            if ($pattern !== '' && str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        return str_replace(['-', '_'], '', strtolower($key));
    }

    private function processString(string $value): string
    {
        $length = mb_strlen($value);
        $isTruncated = $length > $this->maxStringLength;
        $text = $isTruncated ? mb_substr($value, 0, $this->maxStringLength) : $value;

        $text = $this->redactJsonPairs($text);

        return $isTruncated
            ? $text.'…[truncated '.($length - $this->maxStringLength).' chars]'
            : $text;
    }

    /**
     * Raw request bodies are logged as strings, so their secrets cannot be found by array key.
     */
    private function redactJsonPairs(string $text): string
    {
        if (! str_contains($text, '"')) {
            return $text;
        }

        $redacted = preg_replace_callback(
            self::JSON_PAIR_PATTERN,
            function (array $match): string {
                if (! $this->isSensitiveKey(stripcslashes($match[1]))) {
                    return $match[0];
                }

                $value = $match[3];

                if (! str_starts_with($value, '"')) {
                    return '"'.$match[1].'"'.$match[2].$this->replacement;
                }

                $isTerminated = strlen($value) > 1 && str_ends_with($value, '"');

                return '"'.$match[1].'"'.$match[2].'"'.$this->replacement.($isTerminated ? '"' : '');
            },
            $text,
        );

        return $redacted ?? $this->replacement;
    }
}
