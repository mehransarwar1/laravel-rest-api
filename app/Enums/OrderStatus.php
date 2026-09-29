<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canBeCancelled(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }
}
