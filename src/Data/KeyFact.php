<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Guard;

/**
 * One claim intake extracted from the raw input.
 *
 * `sourceRef` points at where the claim came from — a route receipt, an issue,
 * a pointer into the archive — so a fact can be checked without the checker
 * needing the raw body. `confidence` is per-fact because a packet is often
 * solid in one place and guessing in another, and flattening that to a single
 * packet-level number loses exactly the detail downstream needs to hedge.
 */
final readonly class KeyFact
{
    public function __construct(
        public string $statement,
        public ?string $sourceRef = null,
        public ?float $confidence = null,
    ) {
        Guard::requiredString($statement, 'key_facts[].statement');

        if ($sourceRef !== null) {
            Guard::token($sourceRef, 'key_facts[].source_ref');
        }

        if ($confidence !== null) {
            Guard::ratio($confidence, 'key_facts[].confidence');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['statement', 'source_ref', 'confidence'], 'key_facts[]');

        $confidence = $data['confidence'] ?? null;

        return new self(
            statement: Guard::requiredString($data['statement'] ?? null, 'key_facts[].statement'),
            sourceRef: Guard::optionalString($data['source_ref'] ?? null, 'key_facts[].source_ref', 256),
            confidence: $confidence === null ? null : Guard::ratio($confidence, 'key_facts[].confidence'),
        );
    }

    /**
     * @return array{statement: string, source_ref: string|null, confidence: float|null}
     */
    public function toArray(): array
    {
        return [
            'statement' => $this->statement,
            'source_ref' => $this->sourceRef,
            'confidence' => $this->confidence,
        ];
    }
}
