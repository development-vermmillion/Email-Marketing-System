<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'last_attempt_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withTrashed();
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class)->withTrashed();
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }
}
