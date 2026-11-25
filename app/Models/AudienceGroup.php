<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AudienceGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'color'];

    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(Audience::class, 'audience_group_contacts')->withTimestamps();
    }
}
