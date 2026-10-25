<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

use RuntimeException;
use Sigilbase\Dto\Receipt;

/**
 * Receipts as files, one JSON document each, in a directory: the default
 * store. A file is named by the receipt's received_at and the SHA-256 of
 * its canonical body, so a directory listing is in order and the same
 * receipt is never written twice. Each file is exactly what the verifier's
 * --receipt reads.
 */
final class FileReceiptStore implements ReceiptStore
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create the receipt directory [{$this->directory}].");
        }
    }

    public function put(Receipt $receipt): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.self::fileName($receipt);

        if (is_file($path)) {
            return;
        }

        // Written whole, then renamed into place: a reader never sees half.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $receipt->toJson(), LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write the receipt [{$path}].");
        }
    }

    public function all(): iterable
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            // A file in the folder that is not a receipt is not one of ours.
            try {
                $receipt = Receipt::fromJson((string) file_get_contents($file));
            } catch (\JsonException) {
                continue;
            }

            if ($receipt !== null) {
                yield $receipt;
            }
        }
    }

    public static function fileName(Receipt $receipt): string
    {
        $at = $receipt->receivedAt()?->format('Ymd\THis.u\Z') ?? 'unknown';

        return $at.'-'.substr($receipt->id(), 0, 16).'.json';
    }
}
