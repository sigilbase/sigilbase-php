<?php

declare(strict_types=1);

namespace Sigilbase\Internal;

use Sigilbase\Exceptions\UnexpectedResponseException;
use stdClass;

/**
 * Typed accessors for decoded API responses. Responses are decoded with
 * `associative: false` so payload objects survive intact ({} is not []),
 * which means everything arrives as stdClass and needs guarded extraction.
 *
 * @internal
 */
final class Json
{
    public static function string(stdClass $object, string $field): string
    {
        $value = $object->{$field} ?? null;

        if (! is_string($value)) {
            throw self::malformed($field, 'a string', $value);
        }

        return $value;
    }

    public static function nullableString(stdClass $object, string $field): ?string
    {
        $value = $object->{$field} ?? null;

        if ($value !== null && ! is_string($value)) {
            throw self::malformed($field, 'a string or null', $value);
        }

        return $value;
    }

    public static function int(stdClass $object, string $field): int
    {
        $value = $object->{$field} ?? null;

        if (! is_int($value)) {
            throw self::malformed($field, 'an integer', $value);
        }

        return $value;
    }

    public static function nullableInt(stdClass $object, string $field): ?int
    {
        $value = $object->{$field} ?? null;

        if ($value !== null && ! is_int($value)) {
            throw self::malformed($field, 'an integer or null', $value);
        }

        return $value;
    }

    public static function object(stdClass $object, string $field): stdClass
    {
        $value = $object->{$field} ?? null;

        if (! $value instanceof stdClass) {
            throw self::malformed($field, 'an object', $value);
        }

        return $value;
    }

    /**
     * @return list<stdClass>
     */
    public static function objectList(stdClass $object, string $field): array
    {
        $value = $object->{$field} ?? null;

        if (! is_array($value)) {
            throw self::malformed($field, 'an array', $value);
        }

        $items = [];

        foreach ($value as $item) {
            if (! $item instanceof stdClass) {
                throw self::malformed($field, 'an array of objects', $item);
            }

            $items[] = $item;
        }

        return $items;
    }

    private static function malformed(string $field, string $expected, mixed $actual): UnexpectedResponseException
    {
        return new UnexpectedResponseException(
            "The response field [{$field}] should be {$expected}, got ".get_debug_type($actual).'.',
            0,
        );
    }
}
