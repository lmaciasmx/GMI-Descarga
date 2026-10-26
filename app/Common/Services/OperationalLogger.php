<?php

namespace App\Common\Services;

final class OperationalLogger
{
    private const CHANNELS = ['application', 'security', 'integrations', 'scales', 'printing', 'inventory', 'errors', 'audit'];
    private const SENSITIVE_KEYS = ['password', 'token', 'authorization', 'api_key', 'secret', 'cookie'];

    public function __construct(private readonly string $directory)
    {
    }

    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            $channel = 'application';
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0770, true) && ! is_dir($this->directory)) {
            log_message('error', 'Operational log directory is unavailable.');
            return;
        }

        $entry = [
            'timestamp' => date(DATE_ATOM),
            'level' => strtolower($level),
            'message' => $message,
            'context' => $this->redact($context),
        ];
        file_put_contents(
            rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $channel . '-' . date('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
