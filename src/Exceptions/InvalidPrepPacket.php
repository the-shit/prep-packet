<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Exceptions;

use InvalidArgumentException;

/**
 * Every way a packet can be wrong, with a message that says which.
 *
 * The contract fails loudly on purpose. The failure mode this package exists
 * to prevent is silent: a field renamed on one side, parsed as absent on the
 * other, and the packet just quietly gets weaker with nothing to notice it.
 * So an unknown key is an error, an empty packet is an error, and a raw body
 * where a pointer belongs is an error.
 */
final class InvalidPrepPacket extends InvalidArgumentException
{
    public static function missingField(string $field): self
    {
        return new self("prep-packet: required field `{$field}` is missing.");
    }

    public static function emptyField(string $field): self
    {
        return new self("prep-packet: `{$field}` is present but empty. An empty value is never a valid substitute for a real one.");
    }

    public static function wrongType(string $field, string $expected, string $actual): self
    {
        return new self("prep-packet: `{$field}` must be {$expected}, got {$actual}.");
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownFields(array $keys): self
    {
        return new self(
            'prep-packet: unknown field(s) '.implode(', ', array_map(static fn (string $k): string => "`{$k}`", $keys))
            .'. The contract is closed: a field the schema does not define is a producer/consumer drift bug, not an extension point. '
            .'Add it to the schema and bump the version.'
        );
    }

    public static function unsupportedSchemaVersion(string $given, string $supported): self
    {
        return new self(
            "prep-packet: schema version `{$given}` is not supported by this package (supports `{$supported}`). "
            .'Refusing to guess at a packet shape from a different contract version.'
        );
    }

    public static function emptyPacket(): self
    {
        return new self(
            'prep-packet: the packet carries no canonical message, no key facts, no routes fired and no open questions. '
            .'An empty packet is an intake failure, not a valid handoff — emit one with confidence `unusable` and a reason instead.'
        );
    }

    public static function duplicateName(string $collection, string $name): self
    {
        return new self(
            "prep-packet: duplicate `{$name}` in `{$collection}`. Names in this collection identify distinct entries; "
            .'a collision means one silently overwrites the other downstream.'
        );
    }

    /**
     * The central rule of the intake/output split, enforced by the type.
     */
    public static function rawContentInPointer(string $field, string $why): self
    {
        return new self(
            "prep-packet: `{$field}` looks like raw content rather than a pointer ({$why}). "
            .'raw_ref is an identifier the output engine can hand back to intake — never the body itself. '
            .'The output engine must never receive raw input.'
        );
    }

    public static function tooLong(string $field, int $limit, int $actual): self
    {
        return new self("prep-packet: `{$field}` is {$actual} characters, over the {$limit} character limit.");
    }

    public static function outOfRange(string $field, string $range): self
    {
        return new self("prep-packet: `{$field}` must be {$range}.");
    }
}
