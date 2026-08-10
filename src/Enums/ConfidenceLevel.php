<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Enums;

/**
 * How much the intake engine believes its own packet.
 *
 * This exists because of a specific failure mode: Lexi answers fluently from a
 * weak packet — a question Jordan did not ask, phrased well — and nobody can
 * catch it, because she never sees the raw to compare against. The packet has
 * to be able to say "I am not sure", or the only signal downstream gets is
 * confident prose.
 */
enum ConfidenceLevel: string
{
    /** Intent is clear and the facts are grounded. Answer normally. */
    case High = 'high';

    /** Usable, with soft spots. Answer, but hedge where the packet says to. */
    case Medium = 'medium';

    /** Intake is guessing. Prefer asking over answering. */
    case Low = 'low';

    /**
     * Intake could not make sense of the input. Do not answer from this packet;
     * escalate to the human or ask for the ask again.
     */
    case Unusable = 'unusable';

    /**
     * Whether downstream should degrade rather than answer confidently.
     */
    public function shouldDegrade(): bool
    {
        return $this === self::Low || $this === self::Unusable;
    }

    /**
     * Whether the packet may be answered from at all.
     */
    public function isAnswerable(): bool
    {
        return $this !== self::Unusable;
    }

    /**
     * Ordering, so callers can compare thresholds without a match block.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Unusable => 0,
        };
    }

    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }
}
