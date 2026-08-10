<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Enums;

/**
 * Weight of a health/ops flag — the things the output engine must not ignore.
 */
enum FlagSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Whether this flag must be surfaced in the reply rather than merely noted.
     */
    public function demandsAttention(): bool
    {
        return $this === self::Warning || $this === self::Critical;
    }
}
