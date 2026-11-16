<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An accepted payment webhook. Immutable once stored; duplicates never update it.
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $event_type
 * @property bool $livemode
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed> $payload
 * @property string $payload_sha256
 * @property string $correlation_id
 * @property CarbonImmutable $received_at
 */
#[Fillable([
    'provider',
    'event_id',
    'event_type',
    'livemode',
    'occurred_at',
    'payload',
    'payload_sha256',
    'correlation_id',
    'received_at',
])]
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }
}
