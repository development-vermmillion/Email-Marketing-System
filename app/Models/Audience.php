<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Audience extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'company', 'status', 'subscription_status', 'source',
        'consent_at', 'consent_ip', 'unsubscribed_at', 'csv_import_id',
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(AudienceGroup::class, 'audience_group_contacts')->withTimestamps();
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** Contacts that may receive marketing mail right now (suppression is checked separately). */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('subscription_status', SubscriptionStatus::Subscribed->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q->where('email', 'like', $like)->orWhere('name', 'like', $like));
    }
}
