<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmtpSetting extends Model
{
    use HasFactory;

    public const PROVIDERS = ['generic', 'sendgrid', 'mailgun', 'postmark', 'ses'];

    protected $fillable = [
        'name', 'provider', 'host', 'port', 'encryption', 'username', 'password',
        'from_name', 'from_email', 'reply_to_email', 'is_active', 'is_default',
        'rate_per_second', 'rate_per_minute', 'daily_limit', 'webhook_secret',
    ];

    /**
     * Credentials never leave the server: hidden from every array/JSON conversion,
     * so they cannot leak into a response, a log context or an activity-log payload.
     */
    protected $hidden = ['username', 'password', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
            'port' => 'integer',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function getHasPasswordAttribute(): bool
    {
        return ! empty($this->attributes['password']);
    }

    /** The active setting to use when a campaign does not pick one explicitly. */
    public static function resolveDefault(): ?self
    {
        return static::query()->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('id')->first();
    }
}
