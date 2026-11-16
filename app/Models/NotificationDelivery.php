<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Delivery of one payment event to one notification channel.
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $channel
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property string $correlation_id
 */
#[Fillable([
    'provider',
    'event_id',
    'channel',
    'status',
    'attempts',
    'last_error',
    'delivered_at',
    'failed_at',
    'correlation_id',
])]
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    /**
     * Maximum length of the stored last error ("class and message of the last exception").
     */
    public const LAST_ERROR_MAX_LENGTH = 2000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
