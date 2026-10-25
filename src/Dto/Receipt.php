<?php

declare(strict_types=1);

namespace Sigilbase\Dto;

use DateTimeImmutable;
use JsonException;
use Sigilbase\Canonical\CanonicalJson;
use stdClass;

/**
 * A signed ingress receipt: Sigilbase's statement of what it decided about
 * one request, made the moment it decided. The body is kept exactly as
 * decoded (objects as objects), because the signature covers its canonical
 * form and an empty object must stay one.
 *
 * Keep every receipt: a {@see \Sigilbase\Receipts\ReceiptStore} does, and
 * {@see \Sigilbase\Client::exportReceipts()} writes the directory the
 * verifier's --reconcile reads.
 */
final readonly class Receipt
{
    public const VERSION = 'sigilbase-receipt/1';

    public function __construct(
        public stdClass $body,
        public string $signature,
    ) {}

    /**
     * The receipt in a response body: its `receipt`, or null when the
     * response carries none (receipt_pending, or an older instance).
     *
     * @internal
     */
    public static function fromResponse(stdClass $body): ?self
    {
        $receipt = $body->receipt ?? null;

        if (! $receipt instanceof stdClass) {
            return null;
        }

        return self::fromDocument($receipt);
    }

    /**
     * A receipt document, {body, signature}, or the API's envelope
     * {receipt: {body, signature}, receipt_pending}.
     */
    public static function fromDocument(stdClass $document): ?self
    {
        if (($document->receipt ?? null) instanceof stdClass) {
            $document = $document->receipt;
        }

        if (! ($document->body ?? null) instanceof stdClass || ! is_string($document->signature ?? null)) {
            return null;
        }

        return new self($document->body, $document->signature);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);

        return $decoded instanceof stdClass ? self::fromDocument($decoded) : null;
    }

    /**
     * The receipt as a document, {body, signature}: what the verifier reads.
     */
    public function toJson(): string
    {
        return json_encode(
            ['body' => $this->body, 'signature' => $this->signature],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * The exact bytes the receipt key signed.
     */
    public function signedMessage(): string
    {
        return self::VERSION."\n".CanonicalJson::encode($this->body);
    }

    /**
     * A stable identity for storage: the SHA-256 of the canonical body.
     */
    public function id(): string
    {
        return hash('sha256', CanonicalJson::encode($this->body));
    }

    public function outcome(): string
    {
        return (string) ($this->body->outcome ?? '');
    }

    public function stream(): string
    {
        return (string) ($this->body->stream ?? '');
    }

    public function keyId(): string
    {
        return (string) ($this->body->key_id ?? '');
    }

    public function requestDigest(): string
    {
        return (string) ($this->body->request_digest ?? '');
    }

    public function decisionSeq(): ?int
    {
        return is_int($this->body->decision_seq ?? null) ? $this->body->decision_seq : null;
    }

    public function receivedAt(): ?DateTimeImmutable
    {
        return self::time($this->body->received_at ?? null);
    }

    public function signedAt(): ?DateTimeImmutable
    {
        return self::time($this->body->signed_at ?? null);
    }

    public function sealBy(): ?DateTimeImmutable
    {
        return self::time($this->body->seal_by ?? null);
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
