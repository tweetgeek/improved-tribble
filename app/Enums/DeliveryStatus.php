<?php

namespace App\Enums;

/**
 * State of one payment event delivered to one channel (data-model.md §2).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
