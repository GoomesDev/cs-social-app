<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityFeedItem extends Model
{
    protected $fillable = [
        'actor_id', 'type', 'period', 'reference_date', 'payload', 'deduplication_key', 'occurred_at',
    ];

    protected $hidden = ['actor_id', 'deduplication_key', 'created_at', 'updated_at'];

    protected $casts = [
        'payload' => 'array',
        'reference_date' => 'date',
        'occurred_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(Users::class, 'actor_id');
    }
}
