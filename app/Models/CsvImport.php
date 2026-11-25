<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'group_ids' => 'array',
            'header_map' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getProgressAttribute(): int
    {
        return $this->total_rows > 0 ? (int) min(100, floor($this->processed_rows / $this->total_rows * 100)) : ($this->status === 'completed' ? 100 : 0);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
