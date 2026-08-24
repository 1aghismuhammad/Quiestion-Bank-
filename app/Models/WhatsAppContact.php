<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'phone_number',
    'country_code',
    'is_verified',
    'marketing_consent',
    'last_message_sent_at',
])]
class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'marketing_consent' => 'boolean',
            'last_message_sent_at' => 'datetime',
        ];
    }
}
