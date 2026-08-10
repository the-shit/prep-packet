<?php

declare(strict_types=1);

use TheShit\PrepPacket\Data\Confidence;
use TheShit\PrepPacket\Data\HealthOpsFlag;
use TheShit\PrepPacket\Data\OpenQuestion;
use TheShit\PrepPacket\Data\RawRef;
use TheShit\PrepPacket\Enums\ConfidenceLevel;
use TheShit\PrepPacket\Enums\FlagSeverity;
use TheShit\PrepPacket\Enums\QuestionAudience;
use TheShit\PrepPacket\Enums\RouteStatus;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\PrepPacket;

/**
 * The weak-packet failure: intake half-understands the ask, the output engine
 * answers fluently, and the reply is to a question nobody asked. Nothing
 * downstream can catch it by inspection — the output engine never sees the raw.
 * So the packet has to be able to say "I am not sure about this".
 */
it('lets a packet say it is unsure so downstream can degrade', function () {
    $packet = PrepPacket::fromArray(packetArray([
        'confidence' => [
            'level' => 'low',
            'score' => 0.3,
            'reasons' => ['The ask mentions two unrelated projects; I picked the more recent one.'],
        ],
    ]));

    expect($packet->shouldDegrade())->toBeTrue()
        ->and($packet->confidence->level)->toBe(ConfidenceLevel::Low)
        ->and($packet->confidence->reasons)->toHaveCount(1);
});

it('does not ask downstream to degrade on a confident packet', function () {
    expect(PrepPacket::fromArray(packetArray())->shouldDegrade())->toBeFalse();
});

it('demands a reason for anything less than high confidence', function (string $level) {
    expect(fn () => PrepPacket::fromArray(packetArray([
        'confidence' => ['level' => $level, 'reasons' => []],
    ])))->toThrow(InvalidPrepPacket::class, 'requires at least one entry in `confidence.reasons`');
})->with(['medium', 'low', 'unusable']);

it('explains why an unexplained low confidence is useless', function () {
    expect(fn () => Confidence::low([]))
        ->toThrow(InvalidPrepPacket::class, 'without telling it what to check');
});

it('allows high confidence with no reasons, because that is the everyday case', function () {
    expect(Confidence::high(0.95)->reasons)->toBe([]);
});

it('degrades on a blocking question even when confidence is high', function () {
    // Intake can be sure of what it extracted and still be missing the one
    // thing that decides the answer.
    $packet = new PrepPacket(
        intakeId: 'abc',
        canonicalMessage: 'Roll back the deploy.',
        confidence: Confidence::high(),
        rawRef: new RawRef('bifrost', 'x'),
        openQuestions: [new OpenQuestion('Which environment?', QuestionAudience::Human, blocking: true)],
    );

    expect($packet->confidence->shouldDegrade())->toBeFalse()
        ->and($packet->hasBlockingQuestions())->toBeTrue()
        ->and($packet->shouldDegrade())->toBeTrue();
});

it('does not degrade on a non-blocking question', function () {
    $packet = new PrepPacket(
        intakeId: 'abc',
        canonicalMessage: 'Summarise the week.',
        confidence: Confidence::high(),
        rawRef: new RawRef('bifrost', 'x'),
        openQuestions: [new OpenQuestion('Should this include weekends?')],
    );

    expect($packet->shouldDegrade())->toBeFalse();
});

it('marks an unusable packet as not answerable at all', function () {
    $confidence = Confidence::unusable(['No identifiable ask in the input.']);

    expect($confidence->level->isAnswerable())->toBeFalse()
        ->and($confidence->level->shouldDegrade())->toBeTrue()
        ->and($confidence->score)->toBe(0.0);
});

it('orders levels so a consumer can set a threshold', function () {
    expect(ConfidenceLevel::High->atLeast(ConfidenceLevel::Medium))->toBeTrue()
        ->and(ConfidenceLevel::Medium->atLeast(ConfidenceLevel::Medium))->toBeTrue()
        ->and(ConfidenceLevel::Low->atLeast(ConfidenceLevel::Medium))->toBeFalse()
        ->and(ConfidenceLevel::Unusable->atLeast(ConfidenceLevel::Low))->toBeFalse();
});

it('surfaces a failed route as an honest reason the packet is thin', function () {
    $packet = PrepPacket::fromArray(packetArray([
        'confidence' => ['level' => 'medium', 'reasons' => ['The GitHub route timed out; issue links may be incomplete.']],
        'routes_fired' => [
            ['route' => 'github.issues.search', 'status' => 'timed_out'],
            ['route' => 'solo.todo.list', 'status' => 'succeeded'],
        ],
    ]));

    expect($packet->failedRoutes())->toHaveCount(1)
        ->and($packet->failedRoutes()[0]->route)->toBe('github.issues.search')
        ->and($packet->failedRoutes()[0]->status)->toBe(RouteStatus::TimedOut);
});

it('separates flags that must be surfaced from flags that are just context', function () {
    $packet = new PrepPacket(
        intakeId: 'abc',
        canonicalMessage: 'An ask.',
        confidence: Confidence::high(),
        rawRef: new RawRef('bifrost', 'x'),
        healthOpsFlags: [
            new HealthOpsFlag('sleep.debt', FlagSeverity::Critical, 'Third night under five hours.'),
            new HealthOpsFlag('build.cache.cold', FlagSeverity::Info),
        ],
    );

    expect($packet->urgentFlags())->toHaveCount(1)
        ->and($packet->urgentFlags()[0]->flag)->toBe('sleep.debt');
});

it('keeps per-fact confidence, because a packet is rarely uniformly good', function () {
    $packet = PrepPacket::fromArray(packetArray([
        'key_facts' => [
            ['statement' => 'The pipeline moved to Docker.', 'confidence' => 0.95],
            ['statement' => 'It broke on Tuesday.', 'confidence' => 0.4],
        ],
    ]));

    expect($packet->keyFacts[0]->confidence)->toBe(0.95)
        ->and($packet->keyFacts[1]->confidence)->toBe(0.4);
});

it('survives the round trip with its confidence intact', function () {
    $packet = PrepPacket::fromArray(packetArray([
        'confidence' => ['level' => 'medium', 'score' => 0.55, 'reasons' => ['Ambiguous referent for "it".']],
    ]));

    $again = PrepPacket::fromJson($packet->toJson());

    expect($again->confidence->level)->toBe(ConfidenceLevel::Medium)
        ->and($again->confidence->score)->toBe(0.55)
        ->and($again->confidence->reasons)->toBe(['Ambiguous referent for "it".'])
        ->and($again->shouldDegrade())->toBeFalse();
});
