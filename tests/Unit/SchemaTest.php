<?php

declare(strict_types=1);

use TheShit\PrepPacket\Enums\ConfidenceLevel;
use TheShit\PrepPacket\Enums\FlagSeverity;
use TheShit\PrepPacket\Enums\QuestionAudience;
use TheShit\PrepPacket\Enums\RouteStatus;
use TheShit\PrepPacket\PrepPacket;
use TheShit\PrepPacket\Schema;

/**
 * The JSON Schema is the language-neutral statement of the contract; the PHP
 * type is the enforcing implementation. If they drift, the schema becomes
 * stale documentation and the exact problem this package was created to solve
 * reappears inside the package itself.
 *
 * So the two are checked against each other here.
 */
it('ships a schema file', function () {
    expect(Schema::path())->toBeReadableFile();
});

it('declares an explicit version from v1', function () {
    expect(Schema::VERSION)->toBe('1.0.0')
        ->and(Schema::definition()['$id'])->toBe(Schema::ID)
        ->and(Schema::definition()['properties']['schema_version']['const'])->toBe(Schema::VERSION);
});

it('defines exactly the fields the PHP type accepts', function () {
    $schemaFields = array_keys(Schema::definition()['properties']);

    sort($schemaFields);
    $typeFields = Schema::FIELDS;
    sort($typeFields);

    expect($schemaFields)->toBe($typeFields);
});

it('requires exactly the fields the PHP type requires', function () {
    $schemaRequired = Schema::definition()['required'];

    sort($schemaRequired);
    $typeRequired = Schema::REQUIRED;
    sort($typeRequired);

    expect($schemaRequired)->toBe($typeRequired);
});

it('closes the object, matching the type rejecting unknown keys', function () {
    expect(Schema::definition()['additionalProperties'])->toBeFalse();
});

it('closes every nested object too', function () {
    $defs = Schema::definition()['$defs'];

    $objects = array_filter($defs, static fn (array $def): bool => ($def['type'] ?? null) === 'object');

    expect($objects)->not->toBeEmpty();

    foreach ($objects as $name => $def) {
        expect($def['additionalProperties'] ?? null)->toBeFalse("`{$name}` must be closed");
    }
});

it('gives raw_ref no field a body could occupy', function () {
    $rawRef = Schema::definition()['$defs']['rawRef'];

    // Identifiers, a digest, and a size. Nothing that holds content.
    expect(array_keys($rawRef['properties']))
        ->toBe(['store', 'id', 'uri', 'digest', 'byte_size', 'captured_at']);
});

it('constrains pointer fields to a charset raw text cannot be spelled in', function () {
    $token = Schema::definition()['$defs']['token'];

    expect($token['pattern'])->toBe('^[A-Za-z0-9\\-_.:@/+=~#]+$')
        ->and($token['maxLength'])->toBe(256)
        ->and(preg_match('%'.$token['pattern'].'%', rawRamble()))->toBe(0);
});

it('agrees with the PHP guard on what a valid token is', function (string $candidate, bool $valid) {
    // The charset contains `/`, so the regex cannot be `/`-delimited.
    $pattern = '%'.Schema::definition()['$defs']['token']['pattern'].'%';

    expect(preg_match($pattern, $candidate) === 1)->toBe($valid);
})->with([
    ['webhook_call:91827', true],
    ['jordanpartridge/Asgard#17', true],
    ['sha256:6dcd4ce2', true],
    ['2026-08-09T18:22:25Z', true],
    ['has a space', false],
    ["has\nnewline", false],
]);

it('lists the same confidence levels the enum defines', function () {
    $schemaLevels = Schema::definition()['$defs']['confidence']['properties']['level']['enum'];

    expect($schemaLevels)->toBe(array_column(ConfidenceLevel::cases(), 'value'));
});

it('lists the same route statuses the enum defines', function () {
    $schemaStatuses = Schema::definition()['$defs']['routeReceipt']['properties']['status']['enum'];

    expect($schemaStatuses)->toBe(array_column(RouteStatus::cases(), 'value'));
});

it('lists the same flag severities the enum defines', function () {
    $schemaSeverities = Schema::definition()['$defs']['healthOpsFlag']['properties']['severity']['enum'];

    expect($schemaSeverities)->toBe(array_column(FlagSeverity::cases(), 'value'));
});

it('lists the same question audiences the enum defines', function () {
    $schemaAudiences = Schema::definition()['$defs']['openQuestion']['properties']['audience']['enum'];

    expect($schemaAudiences)->toBe(array_column(QuestionAudience::cases(), 'value'));
});

it('requires confidence reasons below high, matching the type', function () {
    $rule = Schema::definition()['$defs']['confidence']['allOf'][0];

    expect($rule['if']['properties']['level']['enum'])->toBe(['medium', 'low', 'unusable'])
        ->and($rule['then']['properties']['reasons']['minItems'])->toBe(1);
});

it('emits packets whose keys match the schema properties', function () {
    $packet = PrepPacket::fromArray(packetArray());

    $emitted = array_keys($packet->toArray());
    $defined = array_keys(Schema::definition()['properties']);

    sort($emitted);
    sort($defined);

    expect($emitted)->toBe($defined);
});
