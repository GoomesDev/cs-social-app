<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyRanking extends Model
{
    protected $fillable = [
        'week',
        'user_id',
        'rating',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(Users::class);
    }
}

