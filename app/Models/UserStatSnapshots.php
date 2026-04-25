<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStatSnapshots extends Model
{
    use HasFactory;

    protected $table = 'user_stat_snapshots';

    protected $fillable = [
        'user_id',
        'snapshot_date',
        'matches',
        'wins',
        'losses',
        'rounds',
        'kills',
        'deaths',
        'mvps',
        'bombs_planted',
        'bombs_defused',
        'headshots',
        'headshot_percentage',
        'kd_ratio',
        'rating',
        'win_rate',
        'impact_score',
        'kdd'
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'matches' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'rounds' => 'integer',
        'kills' => 'integer',
        'deaths' => 'integer',
        'mvps' => 'integer',
        'bombs_planted' => 'integer',
        'bombs_defused' => 'integer',
        'headshots' => 'integer',
        'headshot_percentage' => 'decimal:2',
        'kd_ratio' => 'decimal:2',
        'rating' => 'decimal:2',
        'win_rate' => 'decimal:2',
        'impact_score' => 'decimal:2',
        'kdd' => 'integer'
    ];

    public function user()
    {
        return $this->belongsTo(Users::class);
    }
}