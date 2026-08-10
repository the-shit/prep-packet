<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Enums\QuestionAudience;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\Guard;

/**
 * Something intake could not settle.
 *
 * `blocking` is the important bit. A non-blocking question is context; a
 * blocking one means answering without it produces a confidently wrong reply,
 * which is precisely the weak-packet failure. Downstream that ignores blocking
 * questions has defeated the purpose of the field.
 */
final readonly class OpenQuestion
{
    public function __construct(
        public string $question,
        public QuestionAudience $audience = QuestionAudience::Output,
        public bool $blocking = false,
    ) {
        Guard::requiredString($question, 'open_questions[].question');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['question', 'audience', 'blocking'], 'open_questions[]');

        $audience = $data['audience'] ?? QuestionAudience::Output->value;

        if (! is_string($audience)) {
            throw InvalidPrepPacket::wrongType('open_questions[].audience', 'a string', get_debug_type($audience));
        }

        $blocking = $data['blocking'] ?? false;

        if (! is_bool($blocking)) {
            throw InvalidPrepPacket::wrongType('open_questions[].blocking', 'a boolean', get_debug_type($blocking));
        }

        return new self(
            question: Guard::requiredString($data['question'] ?? null, 'open_questions[].question'),
            audience: QuestionAudience::tryFrom($audience) ?? throw InvalidPrepPacket::wrongType(
                'open_questions[].audience',
                'one of: '.implode(', ', array_column(QuestionAudience::cases(), 'value')),
                $audience,
            ),
            blocking: $blocking,
        );
    }

    /**
     * @return array{question: string, audience: string, blocking: bool}
     */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'audience' => $this->audience->value,
            'blocking' => $this->blocking,
        ];
    }
}
