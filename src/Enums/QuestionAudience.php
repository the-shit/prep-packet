<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Enums;

/**
 * Who an open question is for.
 *
 * The distinction matters: a question for the output engine is something it can
 * reason about from the packet, while a question for the human cannot be
 * answered by anyone downstream and must be asked rather than guessed at.
 */
enum QuestionAudience: string
{
    /** The output engine can resolve this with judgment on the prepared work. */
    case Output = 'output';

    /** Only the human can answer. Downstream must ask, never assume. */
    case Human = 'human';
}
