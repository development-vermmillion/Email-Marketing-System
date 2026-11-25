<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'subject', 'from_name', 'from_email', 'reply_to_email', 'email_template_id',
        'smtp_setting_id', 'group_ids', 'scheduled_at',
        'rate_per_second', 'rate_per_minute', 'batch_size', 'max_attempts', 'retry_delay', 'daily_limit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'group_ids' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function smtpSetting(): BelongsTo
    {
        return $this->belongsTo(SmtpSetting::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CampaignActivity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------- Derived metrics ----------
     * "Sent" means accepted by the SMTP relay. Delivery / bounce / complaint figures only exist
     * if the provider webhooks are configured. Open tracking is approximate (image blocking,
     * Apple Mail Privacy Protection and security scanners distort it). */

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }

    private function engagementBase(): int
    {
        return $this->delivered_count > 0 ? $this->delivered_count : $this->sent_count;
    }

    public function getDeliveryRateAttribute(): float
    {
        return $this->rate($this->delivered_count, $this->sent_count);
    }

    public function getOpenRateAttribute(): float
    {
        return $this->rate($this->opened_count, $this->engagementBase());
    }

    public function getClickRateAttribute(): float
    {
        return $this->rate($this->clicked_count, $this->engagementBase());
    }

    public function getBounceRateAttribute(): float
    {
        return $this->rate($this->bounced_count, $this->sent_count);
    }

    public function getUnsubscribeRateAttribute(): float
    {
        return $this->rate($this->unsubscribed_count, $this->engagementBase());
    }

    /** Percentage of recipients that reached a final state. */
    public function getProgressAttribute(): float
    {
        if ($this->total_recipients === 0) {
            return 0.0;
        }

        $done = $this->total_recipients - $this->queued_count;

        return round(max(0, $done) / $this->total_recipients * 100, 1);
    }

    public function canEdit(): bool
    {
        return $this->trashed() === false && $this->status->isEditable();
    }
}
