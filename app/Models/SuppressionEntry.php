<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use Illuminate\Database\Eloquent\Model;

class SuppressionEntry extends Model
{
    protected $table = 'suppression_list';

    protected $fillable = ['email', 'reason', 'source', 'campaign_id', 'note'];

    protected function casts(): array
    {
        return ['reason' => SuppressionReason::class];
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }
}
