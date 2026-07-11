<?php

declare(strict_types=1);

namespace Sigilbase\Canonical;

use Sigilbase\Exceptions\InvalidPayloadException;
use stdClass;

/**
 * RFC 8785 (JSON Canonicalization Scheme) encoder with the Sigilbase
 * restriction: numbers must be integers with absolute value at most
 * 2^53 - 1. This mirrors the server's canonicaliser and the standalone
 * verifier byte for byte — entry hashes and payload hashes commit to this
 * exact serialisation, so local verification depends on it.
 *
 * Object keys sort by UTF-16 code units. PHP associative arrays encode as
 * objects and list arrays as arrays; an empty PHP array is a JSON array,
 * so pass `new stdClass` (or `(object) []`) for an empty object.
 */
final class CanonicalJson
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
                throw new InvalidPayloadException(
                    "Integer {$value} is outside the exactly representable IEEE-754 range (|n| <= 2^53 - 1)."
                );
            }

            return (string) $value;
        }

        if (is_float($value)) {
            throw new InvalidPayloadException(
                'Non-integer numbers cannot be canonicalised; payloads must use integers only. '
                .'Encode decimals as strings or scaled integers ("amount_pence": 1250) — '
                .'float canonicalisation is a classic source of hash mismatches, so Sigilbase refuses it outright. '
                .'See https://app.sigilbase.io/docs/sending-events.'
            );
        }

        if (is_string($value)) {
            return self::encodeString($value);
        }

        if ($value instanceof stdClass) {
            return self::encodeObject((array) $value);
        }

        if (is_array($value)) {
            if ($value === [] || array_is_list($value)) {
                return '['.implode(',', array_map(self::encode(...), $value)).']';
            }

            return self::encodeObject($value);
        }

        throw new InvalidPayloadException(
            'Payloads may only contain null, booleans, integers, strings, arrays, and objects; got '.get_debug_type($value).'.'
        );
    }

    /**
     * RFC 8785 §3.2.2.2: two-character escapes for the usual suspects,
     * \u00xx for the remaining C0 controls, everything else literal UTF-8.
     */
    private static function encodeString(string $value): string
    {
        self::assertUtf8($value);

        $out = '"';
        $length = strlen($value);
        $i = 0;

        while ($i < $length) {
            $byte = $value[$i];
            $ord = ord($byte);

            if ($byte === '"') {
                $out .= '\\"';
            } elseif ($byte === '\\') {
                $out .= '\\\\';
            } elseif ($ord === 0x08) {
                $out .= '\\b';
            } elseif ($ord === 0x09) {
                $out .= '\\t';
            } elseif ($ord === 0x0A) {
                $out .= '\\n';
            } elseif ($ord === 0x0C) {
                $out .= '\\f';
            } elseif ($ord === 0x0D) {
                $out .= '\\r';
            } elseif ($ord < 0x20) {
                $out .= sprintf('\\u%04x', $ord);
            } else {
                $out .= $byte;
            }

            $i++;
        }

        return $out.'"';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function encodeObject(array $value): string
    {
        $members = [];

        foreach ($value as $key => $item) {
            $key = (string) $key;
            $members[] = [self::utf16be($key), $key, $item];
        }

        usort($members, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $encoded = [];

        foreach ($members as [, $key, $item]) {
            $encoded[] = self::encodeString($key).':'.self::encode($item);
        }

        return '{'.implode(',', $encoded).'}';
    }

    /**
     * UTF-8 to UTF-16BE without mbstring conversion tables: RFC 8785 sorts
     * object keys by UTF-16 code units, with surrogate pairs for astral
     * codepoints.
     */
    private static function utf16be(string $value): string
    {
        self::assertUtf8($value);

        $out = '';
        $length = strlen($value);
        $i = 0;

        while ($i < $length) {
            $byte = ord($value[$i]);

            if ($byte < 0x80) {
                $codepoint = $byte;
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0) {
                $codepoint = (($byte & 0x1F) << 6) | (ord($value[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $codepoint = (($byte & 0x0F) << 12)
                    | ((ord($value[$i + 1]) & 0x3F) << 6)
                    | (ord($value[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $codepoint = (($byte & 0x07) << 18)
                    | ((ord($value[$i + 1]) & 0x3F) << 12)
                    | ((ord($value[$i + 2]) & 0x3F) << 6)
                    | (ord($value[$i + 3]) & 0x3F);
                $i += 4;
            }

            if ($codepoint > 0xFFFF) {
                $codepoint -= 0x10000;
                $out .= pack('n', 0xD800 | ($codepoint >> 10));
                $out .= pack('n', 0xDC00 | ($codepoint & 0x3FF));
            } else {
                $out .= pack('n', $codepoint);
            }
        }

        return $out;
    }

    /**
     * PCRE's u modifier rejects invalid UTF-8 without needing mbstring.
     */
    private static function assertUtf8(string $value): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidPayloadException('Strings must be valid UTF-8 to be canonicalised.');
        }
    }
}
