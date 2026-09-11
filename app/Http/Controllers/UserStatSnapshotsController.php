<?php

namespace App\Http\Controllers;

use App\Models\Users;
use App\Models\UserStatSnapshots;
use App\Services\ScoreCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class UserStatSnapshotsController extends Controller
{
    public function getSnapshotByUser($userId)
    {
        $apiKey = config('services.steam.api_key');
        $apiBase = config('services.steam.api_base');
        $apiPlayerStats = config('services.steam.api_player_stats');
        $appId = config('services.steam.app_id');

        $steamId = Users::find($userId)->steam_id;
        if (! $steamId) {
            return response()->json(['error' => 'Steam ID não encontrado para o usuário'], 404);
        }

        $snapshot = Http::get($apiBase.$apiPlayerStats, [
            'key' => $apiKey,
            'steamid' => $steamId,
            'appid' => $appId,
        ])->json();

        if (empty($snapshot['playerstats']['stats'])) {
            return response()->json(['error' => 'Nenhum dado de estatísticas encontrado'], 404);
        }

        $stats = $snapshot['playerstats']['stats'] ?? [];

        $statsAssoc = collect($stats)->mapWithKeys(function ($item) {
            return [$item['name'] => $item['value']];
        })->toArray();

        $this->createSnapshot($userId, $statsAssoc);
    }

    private function createSnapshot($userId, $stats)
    {
        $kills = $stats['total_kills'] ?? 0;
        $deaths = $stats['total_deaths'] ?? 0;
        $wins = $stats['total_matches_won'] ?? 0;
        $matches = $stats['total_matches_played'] ?? 0;
        $headshots = $stats['total_kills_headshot'] ?? 0;
        $mvps = $stats['total_mvps'] ?? 0;
        $rounds = $stats['total_rounds_played'] ?? 0;

        $kdRatio = ScoreCalculator::calculateKDRatio($kills, $deaths);
        $winRate = ScoreCalculator::calculateWinRate($wins, $matches);
        $headshotPercentage = ScoreCalculator::calculateHeadshotPercentage($headshots, $kills);

        $data = [
            'user_id' => $userId,
            'snapshot_date' => now()->toDateString(),
            'matches' => $matches,
            'rounds' => $rounds,
            'wins' => $wins,
            'losses' => $matches - $wins,
            'kills' => $kills,
            'deaths' => $deaths,
            'mvps' => $mvps,
            'bombs_planted' => $stats['total_planted_bombs'] ?? 0,
            'bombs_defused' => $stats['total_defused_bombs'] ?? 0,
            'headshots' => $headshots,
            'headshot_percentage' => $headshotPercentage,
            'kd_ratio' => $kdRatio,
            'win_rate' => $winRate,
            'rating' => ScoreCalculator::calculateRating($kills, $deaths, $mvps, $headshots, $rounds),
            'impact_score' => ScoreCalculator::calculateImpactScore($kdRatio, $headshotPercentage, $winRate, $mvps, $matches),
            'kdd' => ScoreCalculator::calculateKDD($kills, $deaths),
        ];

        try {
            $snapshot = UserStatSnapshots::updateOrCreate(
                [
                    'user_id' => $userId,
                    'snapshot_date' => now()->toDateString(),
                ],
                $data
            );
        } catch (\Exception $e) {
            \Log::error('Erro ao criar snapshot: '.$e->getMessage());

            return response()->json(['error' => 'Erro ao criar snapshot'], 500);
        }

        return response()->json(['message' => 'Snapshot criado com sucesso']);
    }

    public function getDailyStats($userId, $date = null)
    {
        return $this->getPeriodStats($userId, $date, 'daily');
    }

    public function getWeeklyStats($userId, $startDate = null)
    {
        return $this->getPeriodStats($userId, $startDate, 'weekly');
    }

    private function getPeriodStats($userId, $date, string $period)
    {
        Validator::make(['date' => $date], ['date' => 'nullable|date_format:Y-m-d'])->validate();

        if (! Users::whereKey($userId)->exists()) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $reference = $date === null ? CarbonImmutable::today() : CarbonImmutable::parse($date);

        return response()->json(app(\App\Services\PeriodStats::class)->forUser($userId, $reference, $period));
    }
}
