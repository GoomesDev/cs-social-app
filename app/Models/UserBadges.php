<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBadge extends Model
{
    protected $table = 'user_badges';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'badge_id',
        'reference_month',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getReferenceMonthAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        return substr($value, 0, 7);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badges::class);
    }
}