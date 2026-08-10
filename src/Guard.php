<?php

declare(strict_types=1);

namespace TheShit\PrepPacket;

use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;

/**
 * Shared validation, so every value object rejects the same things the same
 * way and no type quietly accepts what its neighbour refuses.
 */
final class Guard
{
    /**
     * A pointer token: no whitespace, no control characters, bounded length.
     *
     * This charset is the mechanism that makes raw content structurally
     * impossible in a reference field. Human input — the sloppy rambles this
     * whole pipeline exists to keep away from the output engine — always
     * contains spaces or newlines, so it cannot be spelled as a token.
     */
    public const string TOKEN_PATTERN = '/^[A-Za-z0-9\-_.:@\/+=~#]{1,256}$/';

    public static function requiredString(mixed $value, string $field, int $maxLength = 4000): string
    {
        if ($value === null) {
            throw InvalidPrepPacket::missingField($field);
        }

        if (! is_string($value)) {
            throw InvalidPrepPacket::wrongType($field, 'a string', get_debug_type($value));
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidPrepPacket::emptyField($field);
        }

        return self::withinLength($trimmed, $field, $maxLength);
    }

    public static function optionalString(mixed $value, string $field, int $maxLength = 4000): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidPrepPacket::wrongType($field, 'a string or null', get_debug_type($value));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : self::withinLength($trimmed, $field, $maxLength);
    }

    public static function withinLength(string $value, string $field, int $maxLength): string
    {
        $length = mb_strlen($value);

        if ($length > $maxLength) {
            throw InvalidPrepPacket::tooLong($field, $maxLength, $length);
        }

        return $value;
    }

    /**
     * An opaque reference, provably not a body.
     */
    public static function token(mixed $value, string $field): string
    {
        $string = self::requiredString($value, $field, 256);

        self::rejectRawContent($string, $field);

        if (preg_match(self::TOKEN_PATTERN, $string) !== 1) {
            throw InvalidPrepPacket::rawContentInPointer(
                $field,
                'it contains characters outside the pointer charset [A-Za-z0-9-_.:@/+=~#]'
            );
        }

        return $string;
    }

    /**
     * The raw-never rule, stated once.
     *
     * Whitespace is the tell. A pointer has none; a body always does. Checked
     * before the charset test so the error explains the actual problem — "this
     * is a body" — rather than complaining about a stray character.
     */
    public static function rejectRawContent(string $value, string $field): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw InvalidPrepPacket::rawContentInPointer($field, 'it contains line breaks');
        }

        if (preg_match('/\s/u', $value) === 1) {
            throw InvalidPrepPacket::rawContentInPointer($field, 'it contains whitespace');
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    public static function stringList(array $values, string $field, int $maxLength = 4000): array
    {
        $strings = [];

        foreach ($values as $index => $value) {
            $strings[] = self::requiredString($value, "{$field}[{$index}]", $maxLength);
        }

        return $strings;
    }

    /**
     * Reject a collision before it silently overwrites something downstream.
     *
     * @param  list<string>  $names
     */
    public static function uniqueNames(array $names, string $collection): void
    {
        $seen = [];

        foreach ($names as $name) {
            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                throw InvalidPrepPacket::duplicateName($collection, $name);
            }

            $seen[$key] = true;
        }
    }

    public static function ratio(mixed $value, string $field): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw InvalidPrepPacket::wrongType($field, 'a number between 0 and 1', get_debug_type($value));
        }

        $score = (float) $value;

        if ($score < 0.0 || $score > 1.0) {
            throw InvalidPrepPacket::outOfRange($field, 'between 0 and 1 inclusive');
        }

        return $score;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $allowed
     */
    public static function noUnknownFields(array $data, array $allowed, string $context = ''): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $allowed));

        if ($unknown !== []) {
            throw InvalidPrepPacket::unknownFields(
                $context === '' ? $unknown : array_map(static fn (string $k): string => "{$context}.{$k}", $unknown)
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function arrayField(array $data, string $field): array
    {
        $value = $data[$field] ?? [];

        if (! is_array($value)) {
            throw InvalidPrepPacket::wrongType($field, 'an array', get_debug_type($value));
        }

        return $value;
    }
}
