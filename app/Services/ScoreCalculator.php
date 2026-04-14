<?php
namespace App\Services;

class ScoreCalculator
{
    public static function calculateKDRatio(
        int $kills, 
        int $deaths
    ): float {
        return $deaths > 0 ? round($kills / $deaths, 2) : round((float) $kills, 2);
    }

    public static function calculateWinRate(
        int $wins, 
        int $matches
    ): float {
        return $matches > 0 ? round(($wins / $matches) * 100, 2) : 0.0;
    }

    public static function calculateHeadshotPercentage(
        int $headshots, 
        int $kills
        ): float {
        return $kills > 0 ? round(($headshots / $kills) * 100, 2) : 0.0;
    }

    public static function calculateRating(
        int $kills,
        int $deaths,
        int $mvps,
        int $headshots,
        int $rounds
    ): float {
        if ($rounds <= 0) {
            return 0.0;
        }

        $kpr   = $kills / $rounds;
        $dpr   = $deaths / $rounds;
        $mvppr = $mvps / $rounds;
        $hsr   = $kills > 0 ? $headshots / $kills : 0;

        $raw = 0.7 * $kpr
             + 0.3 * (1 - $dpr)
             + 0.1 * $mvppr
             + 0.1 * $hsr;

        return round($raw, 2);
    }

    public static function calculateImpactScore(
        float $kdRatio,
        float $headshotPercentage,
        float $winRate,
        int   $mvps,
        int   $matches
    ): float {
        $kdNorm  = $kdRatio;
        $hsNorm  = ($headshotPercentage / 100) * 0.3;
        $wrNorm  = ($winRate / 100) * 0.3;
        $mvpRate = $matches > 0 ? min(($mvps / $matches), 1.0) * 0.15 : 0;
        $score = 0.2 + ($kdNorm * 0.35) + $hsNorm + $wrNorm + $mvpRate;

        return round($score, 2);
    }
}