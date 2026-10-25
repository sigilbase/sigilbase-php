<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

use RuntimeException;

/**
 * The attempt log as an append-only file of JSON lines: a `started` line
 * written before each request is sent, a `tried` line for each HTTP try
 * (retries included), and a `finished` line when it is answered or fails,
 * joined by the attempt's id. An attempt with no
 * `finished` line, or one finished with an error and no receipt, is a
 * request Sigilbase may never have received.
 */
final class FileAttemptLog implements AttemptLog
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the attempt log directory [{$directory}].");
        }
    }

    public function started(array $attempt): void
    {
        $this->append(['event' => 'started', ...$attempt]);
    }

    public function tried(string $attemptId, array $try): void
    {
        $this->append(['event' => 'tried', 'id' => $attemptId, 'at' => gmdate('Y-m-d\TH:i:s\Z'), ...$try]);
    }

    public function finished(string $attemptId, array $outcome): void
    {
        $this->append(['event' => 'finished', 'id' => $attemptId, 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'), ...$outcome]);
    }

    /**
     * Every line, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $lines = [];

        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, associative: true);

            if (is_array($decoded)) {
                $fields = [];

                foreach ($decoded as $key => $value) {
                    $fields[(string) $key] = $value;
                }

                $lines[] = $fields;
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function append(array $line): void
    {
        $encoded = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($this->path, $encoded, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot write the attempt log [{$this->path}].");
        }
    }
}
