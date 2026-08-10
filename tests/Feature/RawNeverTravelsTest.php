<?php

declare(strict_types=1);

use TheShit\PrepPacket\Data\Confidence;
use TheShit\PrepPacket\Data\KeyFact;
use TheShit\PrepPacket\Data\RawRef;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\PrepPacket;

/**
 * The central rule of the intake/output split, enforced by the type rather
 * than by anyone's discipline.
 *
 * The output engine responds from prepared work and never sees the sloppy
 * truth. If a raw body can reach it, the whole separation is decorative — and
 * nobody downstream could ever notice, because the output engine has nothing
 * to compare against.
 */
it('refuses a raw body where a pointer belongs', function (string $field) {
    expect(fn () => RawRef::fromArray(['store' => 'bifrost', 'id' => 'x', $field => rawRamble()]))
        ->toThrow(InvalidPrepPacket::class, 'looks like raw content rather than a pointer');
})->with(['digest', 'captured_at']);

it('refuses a raw body as the reference id', function () {
    expect(fn () => new RawRef(store: 'bifrost', id: rawRamble()))
        ->toThrow(InvalidPrepPacket::class, 'raw_ref.id');
});

it('refuses a raw body as the store name', function () {
    expect(fn () => new RawRef(store: rawRamble(), id: 'webhook_call:1'))
        ->toThrow(InvalidPrepPacket::class, 'raw_ref.store');
});

it('names line breaks as the reason, so the error teaches the rule', function () {
    expect(fn () => new RawRef(store: 'bifrost', id: "line one\nline two"))
        ->toThrow(InvalidPrepPacket::class, 'it contains line breaks');
});

it('rejects even a single space in a pointer', function () {
    // No hedging on "looks like prose". A pointer has no whitespace at all.
    expect(fn () => new RawRef(store: 'bifrost', id: 'webhook call 1'))
        ->toThrow(InvalidPrepPacket::class, 'it contains whitespace');
});

it('rejects the whole packet when raw_ref is the body itself rather than a reference', function () {
    expect(fn () => PrepPacket::fromArray(packetArray(['raw_ref' => rawRamble()])))
        ->toThrow(InvalidPrepPacket::class, '`raw_ref` must be an object with `store` and `id`');
});

it('has nowhere to put a raw body, so inventing a field fails loudly', function (string $field) {
    expect(fn () => PrepPacket::fromArray(packetArray([$field => rawRamble()])))
        ->toThrow(InvalidPrepPacket::class, 'unknown field');
})->with(['raw', 'raw_body', 'body', 'text', 'original_message', 'raw_message', 'content', 'message']);

it('says why an unknown field is a bug rather than an extension point', function () {
    expect(fn () => PrepPacket::fromArray(packetArray(['raw_body' => 'anything'])))
        ->toThrow(InvalidPrepPacket::class, 'The contract is closed');
});

it('keeps raw out of the serialised packet entirely', function () {
    $packet = PrepPacket::fromArray(packetArray());

    $json = $packet->toJson();

    expect($json)->not->toContain(rawRamble())
        ->and($packet->toArray())->not->toHaveKey('raw')
        ->and($packet->toArray())->not->toHaveKey('raw_body')
        ->and($packet->toArray()['raw_ref'])->toHaveKeys(['store', 'id']);
});

it('round-trips without ever gaining a body field', function () {
    $packet = PrepPacket::fromArray(packetArray());

    $again = PrepPacket::fromJson($packet->toJson());

    expect($again->toArray())->toBe($packet->toArray())
        ->and(array_keys($again->toArray()))->toBe(array_keys($packet->toArray()));
});

it('lets a consumer reason about the raw without receiving it', function () {
    $packet = PrepPacket::fromArray(packetArray([
        'raw_ref' => [
            'store' => 'bifrost',
            'id' => 'webhook_call:91827',
            'digest' => 'sha256:6dcd4ce23d88e2ee9568ba546c007c63d9131c1b',
            'byte_size' => 184,
            'captured_at' => '2026-08-09T18:22:25Z',
        ],
    ]));

    // Size and digest describe the body; neither is the body.
    expect($packet->rawRef->byteSize)->toBe(184)
        ->and($packet->rawRef->digest)->toStartWith('sha256:')
        ->and($packet->toJson())->not->toContain(rawRamble());
});

it('does not let key facts smuggle a body through a source reference', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'key_facts' => [['statement' => 'A claim.', 'source_ref' => rawRamble()]],
    ])))->toThrow(InvalidPrepPacket::class, 'key_facts[].source_ref');
});

it('does not let relevance become a back door for narrative text', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'relevance' => ['issues' => [rawRamble()], 'projects' => [], 'people' => []],
    ])))->toThrow(InvalidPrepPacket::class, 'relevance.issues[0]');
});

it('does not let a route receipt reference carry a body', function () {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'routes_fired' => [['route' => 'github.search', 'status' => 'succeeded', 'ref' => rawRamble()]],
    ])))->toThrow(InvalidPrepPacket::class, 'routes_fired[].ref');
});

it('allows prose only in the fields that are meant to be read by a human', function () {
    // canonical_message, summaries, details and questions are prepared prose —
    // that is the product. The rule is about pointers, not about all text.
    $packet = new PrepPacket(
        intakeId: '01JQ8Z4M7X9K2N5P',
        canonicalMessage: 'A cleaned, well-formed ask with spaces and punctuation.',
        confidence: Confidence::high(),
        rawRef: new RawRef(store: 'bifrost', id: 'webhook_call:1'),
        keyFacts: [new KeyFact('A claim, stated plainly.')],
    );

    expect($packet->canonicalMessage)->toContain(' ');
});
