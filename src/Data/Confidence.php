<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Enums\ConfidenceLevel;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\Guard;

/**
 * The packet's assessment of itself.
 *
 * The failure this field exists to prevent: intake produces a weak packet, the
 * output engine answers it fluently, and the answer is to a question that was
 * never asked. Nobody downstream can catch that by inspection, because the
 * whole design means the output engine never sees the raw to compare against.
 * The only way it can know is if the packet says so.
 *
 * `reasons` is required whenever confidence is not high — an unexplained "I am
 * not sure" gives downstream nothing to act on, while "the ask mentions two
 * unrelated projects and I picked one" tells it exactly what to check.
 */
final readonly class Confidence
{
    /**
     * @param  float|null  $score  Optional finer-grained signal, 0–1.
     * @param  list<string>  $reasons  Why confidence is what it is. Required below `high`.
     */
    public function __construct(
        public ConfidenceLevel $level,
        public ?float $score = null,
        public array $reasons = [],
    ) {
        if ($score !== null) {
            Guard::ratio($score, 'confidence.score');
        }

        foreach ($reasons as $index => $reason) {
            Guard::requiredString($reason, "confidence.reasons[{$index}]");
        }

        if ($level !== ConfidenceLevel::High && $reasons === []) {
            throw new InvalidPrepPacket(
                "prep-packet: confidence `{$level->value}` requires at least one entry in `confidence.reasons`. "
                .'An unexplained low confidence tells downstream to distrust the packet without telling it what to check.'
            );
        }
    }

    /**
     * The everyday case: intake understood the ask.
     */
    public static function high(?float $score = null): self
    {
        return new self(ConfidenceLevel::High, $score);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function medium(array $reasons, ?float $score = null): self
    {
        return new self(ConfidenceLevel::Medium, $score, $reasons);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function low(array $reasons, ?float $score = null): self
    {
        return new self(ConfidenceLevel::Low, $score, $reasons);
    }

    /**
     * Intake could not make sense of the input. Emit this rather than a
     * confident guess, and rather than no packet at all — downstream needs to
     * know the turn happened and failed.
     *
     * @param  list<string>  $reasons
     */
    public static function unusable(array $reasons): self
    {
        return new self(ConfidenceLevel::Unusable, 0.0, $reasons);
    }

    public function shouldDegrade(): bool
    {
        return $this->level->shouldDegrade();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['level', 'score', 'reasons'], 'confidence');

        $level = $data['level'] ?? null;

        if (! is_string($level)) {
            throw InvalidPrepPacket::wrongType('confidence.level', 'a string', get_debug_type($level));
        }

        $score = $data['score'] ?? null;

        return new self(
            level: ConfidenceLevel::tryFrom($level) ?? throw InvalidPrepPacket::wrongType(
                'confidence.level',
                'one of: '.implode(', ', array_column(ConfidenceLevel::cases(), 'value')),
                $level,
            ),
            score: $score === null ? null : Guard::ratio($score, 'confidence.score'),
            reasons: Guard::stringList(Guard::arrayField($data, 'reasons'), 'confidence.reasons'),
        );
    }

    /**
     * @return array{level: string, score: float|null, reasons: list<string>}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'score' => $this->score,
            'reasons' => $this->reasons,
        ];
    }
}
