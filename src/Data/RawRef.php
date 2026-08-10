<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\Guard;
use TheShit\PrepPacket\PrepPacket;

/**
 * Where the raw input lives — never the raw input.
 *
 * This is the central rule of the intake/output split: the output engine
 * responds from prepared work and never sees the sloppy truth. That rule is
 * enforced here by the type rather than by anyone's discipline, because
 * discipline is exactly what fails at 2am under deadline.
 *
 * Two mechanics do the enforcing:
 *
 * 1. **There is no field a body could live in.** Every property is an
 *    identifier, a digest, or a size. A producer that wants to smuggle raw
 *    text through has nowhere to put it — and {@see PrepPacket}
 *    rejects unknown keys, so inventing a field fails loudly too.
 *
 * 2. **The identifier fields only accept pointer-shaped strings.** No
 *    whitespace, no line breaks, bounded length. Human rambles cannot be
 *    spelled in that charset.
 *
 * `digest` and `byteSize` exist so downstream can reason *about* the raw — has
 * it changed, how big was it — without ever holding it. Fetching the body is
 * intake's job, on request, with the pointer.
 */
final readonly class RawRef
{
    /**
     * @param  string  $store  Which archive holds it, e.g. `bifrost`, `intake_raw`.
     * @param  string  $id  Opaque identifier within that store.
     * @param  string|null  $uri  Optional dereferenceable location.
     * @param  string|null  $digest  Optional content hash, e.g. `sha256:abc…`.
     * @param  int|null  $byteSize  Optional size of the raw body, in bytes.
     * @param  string|null  $capturedAt  Optional RFC3339 timestamp of capture.
     */
    public function __construct(
        public string $store,
        public string $id,
        public ?string $uri = null,
        public ?string $digest = null,
        public ?int $byteSize = null,
        public ?string $capturedAt = null,
    ) {
        Guard::token($store, 'raw_ref.store');
        Guard::token($id, 'raw_ref.id');

        if ($uri !== null) {
            Guard::rejectRawContent($uri, 'raw_ref.uri');
            Guard::withinLength($uri, 'raw_ref.uri', 2048);
        }

        if ($digest !== null) {
            Guard::token($digest, 'raw_ref.digest');
        }

        if ($byteSize !== null && $byteSize < 0) {
            throw InvalidPrepPacket::outOfRange('raw_ref.byte_size', 'zero or greater');
        }

        if ($capturedAt !== null) {
            Guard::token($capturedAt, 'raw_ref.captured_at');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['store', 'id', 'uri', 'digest', 'byte_size', 'captured_at'], 'raw_ref');

        $byteSize = $data['byte_size'] ?? null;

        if ($byteSize !== null && ! is_int($byteSize)) {
            throw InvalidPrepPacket::wrongType('raw_ref.byte_size', 'an integer or null', get_debug_type($byteSize));
        }

        return new self(
            store: Guard::requiredString($data['store'] ?? null, 'raw_ref.store', 256),
            id: Guard::requiredString($data['id'] ?? null, 'raw_ref.id', 256),
            uri: Guard::optionalString($data['uri'] ?? null, 'raw_ref.uri', 2048),
            digest: Guard::optionalString($data['digest'] ?? null, 'raw_ref.digest', 256),
            byteSize: $byteSize,
            capturedAt: Guard::optionalString($data['captured_at'] ?? null, 'raw_ref.captured_at', 256),
        );
    }

    /**
     * @return array{store: string, id: string, uri: string|null, digest: string|null, byte_size: int|null, captured_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'store' => $this->store,
            'id' => $this->id,
            'uri' => $this->uri,
            'digest' => $this->digest,
            'byte_size' => $this->byteSize,
            'captured_at' => $this->capturedAt,
        ];
    }
}
