<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Enums;

/**
 * How a route intake already fired turned out.
 *
 * Carried so the output engine does not re-run work intake has done — and, as
 * importantly, so it knows when a route *failed*, which is a reason to trust
 * the packet less rather than a detail to omit.
 */
enum RouteStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case TimedOut = 'timed_out';
    case Partial = 'partial';

    /**
     * Whether this route produced the result it was fired for.
     */
    public function isComplete(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether this outcome should pull the packet's confidence down.
     */
    public function weakensPacket(): bool
    {
        return $this === self::Failed || $this === self::TimedOut || $this === self::Partial;
    }
}
