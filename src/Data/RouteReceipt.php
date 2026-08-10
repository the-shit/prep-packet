<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Enums\RouteStatus;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\Guard;

/**
 * Proof that intake already fired a route, so the output engine does not re-run
 * the tool farm to work out what the human meant.
 *
 * A failed receipt is as valuable as a successful one: it tells downstream that
 * part of the packet rests on work that did not complete. Omitting failures
 * would make a weak packet look strong, which is the exact failure this
 * contract is built to make visible.
 */
final readonly class RouteReceipt
{
    public function __construct(
        public string $route,
        public RouteStatus $status,
        public ?string $summary = null,
        public ?string $ref = null,
        public ?int $durationMs = null,
    ) {
        Guard::token($route, 'routes_fired[].route');

        if ($summary !== null) {
            Guard::requiredString($summary, 'routes_fired[].summary');
        }

        if ($ref !== null) {
            Guard::token($ref, 'routes_fired[].ref');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw InvalidPrepPacket::outOfRange('routes_fired[].duration_ms', 'zero or greater');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['route', 'status', 'summary', 'ref', 'duration_ms'], 'routes_fired[]');

        $status = $data['status'] ?? null;

        if (! is_string($status)) {
            throw InvalidPrepPacket::wrongType('routes_fired[].status', 'a string', get_debug_type($status));
        }

        $duration = $data['duration_ms'] ?? null;

        if ($duration !== null && ! is_int($duration)) {
            throw InvalidPrepPacket::wrongType('routes_fired[].duration_ms', 'an integer or null', get_debug_type($duration));
        }

        return new self(
            route: Guard::requiredString($data['route'] ?? null, 'routes_fired[].route', 256),
            status: RouteStatus::tryFrom($status) ?? throw InvalidPrepPacket::wrongType(
                'routes_fired[].status',
                'one of: '.implode(', ', array_column(RouteStatus::cases(), 'value')),
                $status,
            ),
            summary: Guard::optionalString($data['summary'] ?? null, 'routes_fired[].summary'),
            ref: Guard::optionalString($data['ref'] ?? null, 'routes_fired[].ref', 256),
            durationMs: $duration,
        );
    }

    /**
     * @return array{route: string, status: string, summary: string|null, ref: string|null, duration_ms: int|null}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'ref' => $this->ref,
            'duration_ms' => $this->durationMs,
        ];
    }
}
