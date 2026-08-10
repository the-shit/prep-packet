<?php

declare(strict_types=1);

use TheShit\PrepPacket\Data\Confidence;
use TheShit\PrepPacket\Data\HealthOpsFlag;
use TheShit\PrepPacket\Data\RawRef;
use TheShit\PrepPacket\Data\RouteReceipt;
use TheShit\PrepPacket\Enums\RouteStatus;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\PrepPacket;

/**
 * The failure mode this package exists to prevent is silent: one side renames
 * a field, the other parses it as absent, nothing errors, and the packet just
 * quietly gets weaker. So everything wrong throws, with a message that says
 * what and why.
 */
it('rejects a packet missing a required field', function (string $field) {
    $data = packetArray();
    unset($data[$field]);

    expect(fn () => PrepPacket::fromArray($data))->toThrow(InvalidPrepPacket::class);
})->with(['intake_id', 'canonical_message', 'confidence', 'raw_ref']);

it('rejects an empty canonical message rather than handing over a blank ask', function (string $blank) {
    expect(fn () => PrepPacket::fromArray(packetArray(['canonical_message' => $blank])))
        ->toThrow(InvalidPrepPacket::class, 'present but empty');
})->with(['', '   ', "\n\t "]);

it('rejects an entirely empty packet', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'key_facts' => [],
        'routes_fired' => [],
        'open_questions' => [],
        'health_ops_flags' => [],
        'relevance' => ['issues' => [], 'projects' => [], 'people' => []],
    ])))->toThrow(InvalidPrepPacket::class, 'An empty packet is an intake failure');
});

it('tells the producer what to emit instead of an empty packet', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'key_facts' => [],
        'routes_fired' => [],
        'relevance' => ['issues' => [], 'projects' => [], 'people' => []],
    ])))->toThrow(InvalidPrepPacket::class, 'confidence `unusable`');
});

it('allows a genuinely empty packet only when it admits it is unusable', function () {
    // Intake failed to understand the input. That is a real outcome and must be
    // reportable — downstream needs to know the turn happened and went nowhere.
    $packet = PrepPacket::fromArray(packetArray([
        'confidence' => ['level' => 'unusable', 'reasons' => ['Could not identify an ask in the input.']],
        'key_facts' => [],
        'routes_fired' => [],
        'open_questions' => [],
        'health_ops_flags' => [],
        'relevance' => ['issues' => [], 'projects' => [], 'people' => []],
    ]));

    expect($packet->shouldDegrade())->toBeTrue()
        ->and($packet->confidence->level->isAnswerable())->toBeFalse();
});

it('rejects unknown fields instead of ignoring them', function () {
    expect(fn () => PrepPacket::fromArray(packetArray(['canonical_mesage' => 'typo']))) // deliberate typo
        ->toThrow(InvalidPrepPacket::class, '`canonical_mesage`');
});

it('rejects unknown fields inside nested objects too', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'raw_ref' => ['store' => 'bifrost', 'id' => 'x', 'payload' => 'oops'],
    ])))->toThrow(InvalidPrepPacket::class, 'raw_ref.payload');
});

it('rejects a name collision in routes fired', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'routes_fired' => [
            ['route' => 'github.issues.search', 'status' => 'succeeded'],
            ['route' => 'github.issues.search', 'status' => 'failed'],
        ],
    ])))->toThrow(InvalidPrepPacket::class, 'duplicate');
});

it('treats a name collision as a silent-overwrite bug in its message', function () {
    expect(fn () => new PrepPacket(
        intakeId: 'abc',
        canonicalMessage: 'An ask.',
        confidence: Confidence::high(),
        rawRef: new RawRef('bifrost', 'x'),
        routesFired: [
            new RouteReceipt('solo.todo.list', RouteStatus::Succeeded),
            new RouteReceipt('solo.todo.list', RouteStatus::Skipped),
        ],
    ))->toThrow(InvalidPrepPacket::class, 'silently overwrites the other');
});

it('rejects a name collision in health ops flags', function () {
    expect(fn () => new PrepPacket(
        intakeId: 'abc',
        canonicalMessage: 'An ask.',
        confidence: Confidence::high(),
        rawRef: new RawRef('bifrost', 'x'),
        healthOpsFlags: [
            new HealthOpsFlag('disk.pressure'),
            new HealthOpsFlag('disk.pressure'),
        ],
    ))->toThrow(InvalidPrepPacket::class, 'health_ops_flags');
});

it('catches a collision that differs only by case', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'routes_fired' => [
            ['route' => 'Solo.Todo.List', 'status' => 'succeeded'],
            ['route' => 'solo.todo.list', 'status' => 'succeeded'],
        ],
    ])))->toThrow(InvalidPrepPacket::class, 'duplicate');
});

it('rejects a wrong type with both the expected and actual type named', function () {
    expect(fn () => PrepPacket::fromArray(packetArray(['canonical_message' => 42])))
        ->toThrow(InvalidPrepPacket::class, 'must be a string, got int');
});

it('rejects an unknown enum value and lists the valid ones', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'routes_fired' => [['route' => 'x.y', 'status' => 'kinda_worked']],
    ])))->toThrow(InvalidPrepPacket::class, 'succeeded, failed, skipped, timed_out, partial');
});

it('rejects malformed JSON rather than returning an empty packet', function () {
    expect(fn () => PrepPacket::fromJson('{not json'))->toThrow(InvalidPrepPacket::class);
});

it('rejects a JSON array where an object belongs', function () {
    expect(fn () => PrepPacket::fromJson('"just a string"'))
        ->toThrow(InvalidPrepPacket::class, 'must be a JSON object');
});

it('rejects a packet from a different schema version rather than guessing', function () {
    expect(fn () => PrepPacket::fromArray(packetArray(['schema_version' => '2.0.0'])))
        ->toThrow(InvalidPrepPacket::class, 'Refusing to guess');
});

it('rejects an out-of-range confidence score', function (float $score) {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'confidence' => ['level' => 'high', 'score' => $score],
    ])))->toThrow(InvalidPrepPacket::class, 'between 0 and 1');
})->with([-0.1, 1.5, 42.0]);

it('rejects an over-long canonical message', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'canonical_message' => str_repeat('a', 20001),
    ])))->toThrow(InvalidPrepPacket::class, 'over the 20000 character limit');
});
