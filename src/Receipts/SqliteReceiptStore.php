<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

use PDO;
use Sigilbase\Dto\Receipt;

/**
 * Receipts in a SQLite database (needs ext-pdo_sqlite): one row per
 * receipt, keyed by the SHA-256 of its canonical body, the document kept
 * verbatim beside the fields worth querying.
 */
final class SqliteReceiptStore implements ReceiptStore
{
    private readonly PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sigilbase_receipts (
                id TEXT PRIMARY KEY,
                stream TEXT NOT NULL,
                outcome TEXT NOT NULL,
                decision_seq INTEGER,
                received_at TEXT NOT NULL,
                document TEXT NOT NULL
            )
            SQL);
    }

    public function put(Receipt $receipt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO sigilbase_receipts (id, stream, outcome, decision_seq, received_at, document) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $receipt->id(),
            $receipt->stream(),
            $receipt->outcome(),
            $receipt->decisionSeq(),
            (string) ($receipt->body->received_at ?? ''),
            $receipt->toJson(),
        ]);
    }

    public function all(): iterable
    {
        $statement = $this->pdo->query('SELECT document FROM sigilbase_receipts ORDER BY received_at, id');

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $document = is_array($row) ? ($row['document'] ?? null) : null;
            $receipt = is_string($document) ? Receipt::fromJson($document) : null;

            if ($receipt !== null) {
                yield $receipt;
            }
        }
    }
}
