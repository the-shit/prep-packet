<?php

declare(strict_types=1);

namespace TheShit\PrepPacket;

use TheShit\PrepPacket\Data\Confidence;
use TheShit\PrepPacket\Data\HealthOpsFlag;
use TheShit\PrepPacket\Data\KeyFact;
use TheShit\PrepPacket\Data\OpenQuestion;
use TheShit\PrepPacket\Data\RawRef;
use TheShit\PrepPacket\Data\Relevance;
use TheShit\PrepPacket\Data\RouteReceipt;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;

/**
 * What the intake engine hands the output engine.
 *
 * The whole point of the split: intake knows the sloppy truth, output responds
 * from prepared work. To the output engine the human is always articulate,
 * because it never sees anything else — {@see RawRef} is a pointer and there is
 * no field in this type a body could occupy.
 *
 * Three properties make this a contract rather than a suggestion:
 *
 * 1. **Immutable.** A packet is a record of one intake, not a mutable bag
 *    downstream can top up.
 * 2. **Closed.** {@see self::fromArray()} rejects unknown keys. A field one
 *    side renamed does not silently vanish on the other — it throws. Silent
 *    drift is the failure mode this package exists to prevent.
 * 3. **Self-assessing.** {@see Confidence} lets a weak packet say so, so
 *    downstream can degrade instead of answering the wrong question fluently.
 *
 * `intakeId` doubles as the observability trace id, so one turn — raw intake,
 * routes fired, packet handoff, the reply — is a single trace across both apps
 * rather than a parallel correlation scheme.
 */
final readonly class PrepPacket
{
    /**
     * @param  list<KeyFact>  $keyFacts
     * @param  list<RouteReceipt>  $routesFired
     * @param  list<OpenQuestion>  $openQuestions
     * @param  list<HealthOpsFlag>  $healthOpsFlags
     */
    public function __construct(
        public string $intakeId,
        public string $canonicalMessage,
        public Confidence $confidence,
        public RawRef $rawRef,
        public array $keyFacts = [],
        public array $routesFired = [],
        public array $openQuestions = [],
        public array $healthOpsFlags = [],
        public Relevance $relevance = new Relevance,
        public string $schemaVersion = Schema::VERSION,
    ) {
        Guard::token($intakeId, 'intake_id');
        Guard::requiredString($canonicalMessage, 'canonical_message', 20000);

        if ($schemaVersion !== Schema::VERSION) {
            throw InvalidPrepPacket::unsupportedSchemaVersion($schemaVersion, Schema::VERSION);
        }

        Guard::uniqueNames(array_map(static fn (RouteReceipt $r): string => $r->route, $routesFired), 'routes_fired');
        Guard::uniqueNames(array_map(static fn (HealthOpsFlag $f): string => $f->flag, $healthOpsFlags), 'health_ops_flags');

        // A packet with a canonical message but nothing behind it is intake
        // reporting success it did not have. If there is genuinely nothing,
        // that is an `unusable` packet with reasons, not a thin happy one.
        if ($this->carriesNothing() && ! $confidence->level->shouldDegrade()) {
            throw InvalidPrepPacket::emptyPacket();
        }
    }

    /**
     * Parse an untrusted array — the wire form — into a validated packet.
     *
     * Everything is checked: required fields, types, unknown keys, name
     * collisions, and the raw-never rule. There is no lenient mode.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, Schema::FIELDS);

        $version = $data['schema_version'] ?? Schema::VERSION;

        if (! is_string($version)) {
            throw InvalidPrepPacket::wrongType('schema_version', 'a string', get_debug_type($version));
        }

        if ($version !== Schema::VERSION) {
            throw InvalidPrepPacket::unsupportedSchemaVersion($version, Schema::VERSION);
        }

        $rawRef = $data['raw_ref'] ?? null;

        if (! is_array($rawRef)) {
            // Notably this is what a producer hits if it tries to pass the body
            // itself: `raw_ref` as a string is not a reference.
            throw InvalidPrepPacket::wrongType('raw_ref', 'an object with `store` and `id`', get_debug_type($rawRef));
        }

        $confidence = $data['confidence'] ?? null;

        if (! is_array($confidence)) {
            throw InvalidPrepPacket::wrongType('confidence', 'an object with a `level`', get_debug_type($confidence));
        }

        $relevance = $data['relevance'] ?? [];

        if (! is_array($relevance)) {
            throw InvalidPrepPacket::wrongType('relevance', 'an object or null', get_debug_type($relevance));
        }

        return new self(
            intakeId: Guard::requiredString($data['intake_id'] ?? null, 'intake_id', 256),
            canonicalMessage: Guard::requiredString($data['canonical_message'] ?? null, 'canonical_message', 20000),
            confidence: Confidence::fromArray($confidence),
            rawRef: RawRef::fromArray($rawRef),
            keyFacts: array_map(
                static fn (mixed $entry): KeyFact => KeyFact::fromArray(self::objectField($entry, 'key_facts[]')),
                array_values(Guard::arrayField($data, 'key_facts')),
            ),
            routesFired: array_map(
                static fn (mixed $entry): RouteReceipt => RouteReceipt::fromArray(self::objectField($entry, 'routes_fired[]')),
                array_values(Guard::arrayField($data, 'routes_fired')),
            ),
            openQuestions: array_map(
                static fn (mixed $entry): OpenQuestion => OpenQuestion::fromArray(self::objectField($entry, 'open_questions[]')),
                array_values(Guard::arrayField($data, 'open_questions')),
            ),
            healthOpsFlags: array_map(
                static fn (mixed $entry): HealthOpsFlag => HealthOpsFlag::fromArray(self::objectField($entry, 'health_ops_flags[]')),
                array_values(Guard::arrayField($data, 'health_ops_flags')),
            ),
            relevance: Relevance::fromArray($relevance),
            schemaVersion: $version,
        );
    }

    /**
     * @throws InvalidPrepPacket when the JSON is malformed or not an object.
     */
    public static function fromJson(string $json): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw InvalidPrepPacket::wrongType('packet', 'a JSON object', get_debug_type($decoded));
        }

        return self::fromArray($decoded);
    }

    /**
     * Whether downstream should answer normally, or hedge and ask.
     */
    public function shouldDegrade(): bool
    {
        return $this->confidence->shouldDegrade() || $this->hasBlockingQuestions();
    }

    public function hasBlockingQuestions(): bool
    {
        foreach ($this->openQuestions as $question) {
            if ($question->blocking) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flags the output engine must surface rather than merely note.
     *
     * @return list<HealthOpsFlag>
     */
    public function urgentFlags(): array
    {
        return array_values(array_filter(
            $this->healthOpsFlags,
            static fn (HealthOpsFlag $flag): bool => $flag->severity->demandsAttention(),
        ));
    }

    /**
     * Routes that did not complete — the honest reason a packet may be thinner
     * than it looks.
     *
     * @return list<RouteReceipt>
     */
    public function failedRoutes(): array
    {
        return array_values(array_filter(
            $this->routesFired,
            static fn (RouteReceipt $receipt): bool => $receipt->status->weakensPacket(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'intake_id' => $this->intakeId,
            'canonical_message' => $this->canonicalMessage,
            'confidence' => $this->confidence->toArray(),
            'key_facts' => array_map(static fn (KeyFact $f): array => $f->toArray(), $this->keyFacts),
            'routes_fired' => array_map(static fn (RouteReceipt $r): array => $r->toArray(), $this->routesFired),
            'open_questions' => array_map(static fn (OpenQuestion $q): array => $q->toArray(), $this->openQuestions),
            'health_ops_flags' => array_map(static fn (HealthOpsFlag $f): array => $f->toArray(), $this->healthOpsFlags),
            'relevance' => $this->relevance->toArray(),
            'raw_ref' => $this->rawRef->toArray(),
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    private function carriesNothing(): bool
    {
        return $this->keyFacts === []
            && $this->routesFired === []
            && $this->openQuestions === []
            && $this->healthOpsFlags === []
            && $this->relevance->isEmpty();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function objectField(mixed $entry, string $field): array
    {
        if (! is_array($entry)) {
            throw InvalidPrepPacket::wrongType($field, 'an object', get_debug_type($entry));
        }

        return $entry;
    }
}
