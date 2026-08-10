<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Enums\FlagSeverity;
use TheShit\PrepPacket\Exceptions\InvalidPrepPacket;
use TheShit\PrepPacket\Guard;

/**
 * Something the output engine must not ignore — a failing service, a health
 * signal, an ops condition that changes what a good reply looks like.
 *
 * `flag` is a stable token rather than prose so downstream can branch on it;
 * `detail` carries the human-readable part.
 */
final readonly class HealthOpsFlag
{
    public function __construct(
        public string $flag,
        public FlagSeverity $severity = FlagSeverity::Warning,
        public ?string $detail = null,
        public ?string $ref = null,
    ) {
        Guard::token($flag, 'health_ops_flags[].flag');

        if ($detail !== null) {
            Guard::requiredString($detail, 'health_ops_flags[].detail');
        }

        if ($ref !== null) {
            Guard::token($ref, 'health_ops_flags[].ref');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['flag', 'severity', 'detail', 'ref'], 'health_ops_flags[]');

        $severity = $data['severity'] ?? FlagSeverity::Warning->value;

        if (! is_string($severity)) {
            throw InvalidPrepPacket::wrongType('health_ops_flags[].severity', 'a string', get_debug_type($severity));
        }

        return new self(
            flag: Guard::requiredString($data['flag'] ?? null, 'health_ops_flags[].flag', 256),
            severity: FlagSeverity::tryFrom($severity) ?? throw InvalidPrepPacket::wrongType(
                'health_ops_flags[].severity',
                'one of: '.implode(', ', array_column(FlagSeverity::cases(), 'value')),
                $severity,
            ),
            detail: Guard::optionalString($data['detail'] ?? null, 'health_ops_flags[].detail'),
            ref: Guard::optionalString($data['ref'] ?? null, 'health_ops_flags[].ref', 256),
        );
    }

    /**
     * @return array{flag: string, severity: string, detail: string|null, ref: string|null}
     */
    public function toArray(): array
    {
        return [
            'flag' => $this->flag,
            'severity' => $this->severity->value,
            'detail' => $this->detail,
            'ref' => $this->ref,
        ];
    }
}
