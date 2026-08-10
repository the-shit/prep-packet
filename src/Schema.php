<?php

declare(strict_types=1);

namespace TheShit\PrepPacket;

use RuntimeException;

/**
 * The versioned contract, addressable from both sides.
 *
 * The PHP type is the enforcing implementation; the JSON Schema shipped
 * alongside it is the language-neutral statement of the same shape, for any
 * consumer that is not PHP. A test asserts the two agree field-for-field —
 * without that, the schema becomes stale documentation and the drift this
 * package exists to prevent moves into the package itself.
 *
 * Versioning is explicit from v1. A packet declaring any other version is
 * rejected rather than parsed optimistically.
 */
final class Schema
{
    /**
     * Bump on any change to the packet shape. Consumers pin against this.
     */
    public const string VERSION = '1.0.0';

    public const string ID = 'https://the-shit.dev/schemas/prep-packet/v1.json';

    /**
     * Every field the contract defines. Anything else is drift.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'schema_version',
        'intake_id',
        'canonical_message',
        'confidence',
        'key_facts',
        'routes_fired',
        'open_questions',
        'health_ops_flags',
        'relevance',
        'raw_ref',
    ];

    /**
     * Fields a packet cannot be built without.
     *
     * @var list<string>
     */
    public const array REQUIRED = [
        'schema_version',
        'intake_id',
        'canonical_message',
        'confidence',
        'raw_ref',
    ];

    public static function path(): string
    {
        return dirname(__DIR__).'/resources/schema/prep-packet-v1.json';
    }

    /**
     * The JSON Schema document, decoded.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $path = self::path();
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("prep-packet: cannot read the schema at {$path}.");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('prep-packet: the shipped schema is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
