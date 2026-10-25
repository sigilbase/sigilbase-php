<?php

declare(strict_types=1);

namespace Sigilbase\Receipts;

use Sigilbase\Dto\Receipt;

/**
 * Where the receipts a client is given are kept. Every receipt the client
 * receives is put here, refusals included, so a submitter can later show
 * exactly what Sigilbase told it. Putting the same receipt twice keeps one.
 */
interface ReceiptStore
{
    public function put(Receipt $receipt): void;

    /**
     * Every stored receipt, oldest first.
     *
     * @return iterable<Receipt>
     */
    public function all(): iterable;
}
