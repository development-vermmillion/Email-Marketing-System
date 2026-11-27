<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailValidationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deactivate_invalid' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AudienceGroup::class, 'group_id');
    }

    public function getProgressAttribute(): int
    {
        return $this->total > 0 ? (int) min(100, floor($this->processed / $this->total * 100)) : ($this->status === 'completed' ? 100 : 0);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            'unchecked' => 'Contacts not checked yet',
            'all' => 'All contacts (re-check everything)',
            'group' => 'Group: '.($this->group?->name ?? '(deleted)'),
            default => $this->scope,
        };
    }
}
