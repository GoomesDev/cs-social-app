<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStatSnapshot extends Model
{
    use HasFactory;

    protected $table = 'user_stat_snapshots';

    protected $fillable = [
        'user_id',
        'snapshot_date',
        'matches',
        'wins',
        'losses',
        'kills',
        'deaths',
        'assists',
        'mvps',
        'bomb_planted',
        'bomb_defused',
        'headshots',
        'kd_ratio',
        'rating',
        'win_rate',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'matches' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'kills' => 'integer',
        'deaths' => 'integer',
        'assists' => 'integer',
        'mvps' => 'integer',
        'bomb_planted' => 'integer',
        'bomb_defused' => 'integer',
        'headshots' => 'integer',
        'kd_ratio' => 'decimal:2',
        'rating' => 'decimal:2',
        'win_rate' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(Users::class);
    }

    public function getKdRatioAttribute($value)
    {
        if ($value !== null) {
            return round($value, 2);
        }

        $kills = $this->kills ?? 0;
        $deaths = $this->deaths ?? 0;

        return $deaths > 0
            ? round($kills / $deaths, 2)
            : round($kills, 2);
    }

    public function getWinRateAttribute($value)
    {
        if ($value !== null) {
            return round($value, 2);
        }

        $wins = $this->wins ?? 0;
        $matches = $this->matches ?? 0;

        return $matches > 0
            ? round(($wins / $matches) * 100, 2)
            : 0;
    }

    public function getRatingAttribute($value)
    {
        if ($value !== null) {
            return round($value, 2);
        }

        $kd = $this->kd_ratio;
        $winRate = $this->win_rate / 100;

        return round($kd + $winRate, 2);
    }
}