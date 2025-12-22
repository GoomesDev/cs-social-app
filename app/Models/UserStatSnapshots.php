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

    public function getHeadshotPercentageAttribute($value)
    {
        if ($value !== null) {
            return round($value, 2);
        }

        $kills = $this->kills ?? 0;
        $headshots = $this->headshots ?? 0;
        return $kills > 0
            ? round(($headshots / $kills) * 100, 2)
            : 0;
    }

    public function getRatingAttribute($value)
    {
        if ($this->rounds === null) {
            $rounds = $this->matches > 0 ? $this->matches * 30 : 0;
        }
        if ($this->rounds <= 0) {
            return null;
        }

        $kills = $this->kills ?? 0;
        $deaths = $this->deaths ?? 0;
        $mvps = $this->mvps ?? 0;
        $headshots = $this->headshots ?? 0;

        $kpr = $kills / $this->rounds;
        $dpr = $deaths / $this->rounds;
        $mvppr = $mvps / $this->rounds;
        $hs = $kills > 0 ? $headshots / $kills : 0;

        $rating = 0.7 * $kpr + 0.3 * (1 - $dpr) + 0.1 * $mvppr + 0.1 * $hs;
        return round($rating, 2);
    }
}