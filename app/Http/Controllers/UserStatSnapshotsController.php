<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users;
use Illuminate\Support\Facades\Http;
use App\Models\UserStatSnapshots;
use App\Services\ScoreCalculator;

class UserStatSnapshotsController extends Controller
{
    public function getSnapshotByUser($userId)
    {
        $apiKey = config('services.steam.api_key');
        $apiBase = config('services.steam.api_base');
        $apiPlayerStats = config('services.steam.api_player_stats');
        $appId = config('services.steam.app_id');

        $steamId = Users::find($userId)->steam_id;
        if (!$steamId) {
            return response()->json(['error' => 'Steam ID não encontrado para o usuário'], 404);
        }

        $snapshot = Http::get($apiBase . $apiPlayerStats, [
            'key' => $apiKey,
            'steamid' => $steamId,
            'appid' => $appId
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
        $kills     = $stats['total_kills'] ?? 0;
        $deaths    = $stats['total_deaths'] ?? 0;
        $wins      = $stats['total_matches_won'] ?? 0;
        $matches   = $stats['total_matches_played'] ?? 0;
        $headshots = $stats['total_kills_headshot'] ?? 0;
        $mvps      = $stats['total_mvps'] ?? 0;
        $rounds    = $stats['total_rounds_played'] ?? 0;

        $kdRatio             = ScoreCalculator::calculateKDRatio($kills, $deaths);
        $winRate             = ScoreCalculator::calculateWinRate($wins, $matches);
        $headshotPercentage  = ScoreCalculator::calculateHeadshotPercentage($headshots, $kills);

        $data = [
            'user_id'             => $userId,
            'snapshot_date'       => now()->toDateString(),
            'matches'             => $matches,
            'rounds'              => $rounds,
            'wins'                => $wins,
            'losses'              => $matches - $wins,
            'kills'               => $kills,
            'deaths'              => $deaths,
            'mvps'                => $mvps,
            'bombs_planted'       => $stats['total_planted_bombs'] ?? 0,
            'bombs_defused'       => $stats['total_defused_bombs'] ?? 0,
            'headshots'           => $headshots,
            'headshot_percentage' => $headshotPercentage,
            'kd_ratio'            => $kdRatio,
            'win_rate'            => $winRate,
            'rating'              => ScoreCalculator::calculateRating($kills, $deaths, $mvps, $headshots, $rounds),
            'impact_score'        => ScoreCalculator::calculateImpactScore($kdRatio, $headshotPercentage, $winRate, $mvps, $matches),
            'kdd'                 => ScoreCalculator::calculateKDD($kills, $deaths),
        ];

        try {
            $snapshot = UserStatSnapshots::updateOrCreate(
                [
                    'user_id'       => $userId,
                    'snapshot_date' => now()->toDateString(),
                ],
                $data
            );
        } catch (\Exception $e) {
            \Log::error('Erro ao criar snapshot: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao criar snapshot'], 500);
        }

        return response()->json(['message' => 'Snapshot criado com sucesso']);
    }

    public function getDailyStats($userId, $date)
    {
        $snapshots = UserStatSnapshots::where('user_id', $userId);
        
        if ($date) {
            $snapshots->where('snapshot_date', $date);
        }
        
        $snapshots = $snapshots
            ->orderBy('snapshot_date', 'desc')
            ->take(2)
            ->get();

        if ($snapshots->count() < 2) {
            return response()->json(['error' => 'Não há snapshots suficientes para calcular stats diários'], 400);
        }

        $current  = $snapshots[0];
        $previous = $snapshots[1];

        $daysBetween = $previous->snapshot_date->diffInDays($current->snapshot_date);

        if ($daysBetween > 1) {
            return response()->json([
                'error' => 'Snapshots muito distantes para calcular stats diários',
                'days_between' => $daysBetween,
            ], 422);
        }

        $fields = [
            'kills', 'deaths', 'mvps',
            'bombs_planted', 'bombs_defused', 'headshots',
            'matches', 'wins', 'losses', 'rounds',
        ];

        $diff = [];
        foreach ($fields as $field) {
            $diff[$field] = ($current->$field ?? 0) - ($previous->$field ?? 0);
        }

        $temp = new UserStatSnapshots($diff);
        $diff['kd_ratio']            = $temp->kd_ratio;
        $diff['win_rate']            = $temp->win_rate;
        $diff['headshot_percentage'] = $temp->headshot_percentage;
        $diff['rating']              = $temp->rating;

        return response()->json(['daily_stats' => $diff]);
    }

    public function getWeeklyStats($userId, $startDate = null)
    {
        if (!$startDate) {
            $startDate = now()->startOfWeek(0);
        } else {
            $startDate = Carbon::parse($startDate)->startOfWeek(0);
        }
        
        $endDate = $startDate->copy()->endOfWeek(0);

        $snapshots = UserStatSnapshots::where('user_id', $userId)
            ->whereBetween('snapshot_date', [$startDate, $endDate])
            ->orderBy('snapshot_date', 'asc')
            ->get();

        if ($snapshots->count() < 2) {
            return response()->json(['error' => 'Não há snapshots suficientes para calcular stats semanais'], 400);
        }

        $first  = $snapshots->first();
        $last   = $snapshots->last();

        $daysBetween = $first->snapshot_date->diffInDays($last->snapshot_date);

        $fields = [
            'kills', 'deaths', 'mvps',
            'bombs_planted', 'bombs_defused', 'headshots',
            'matches', 'wins', 'losses', 'rounds',
        ];

        $diff = [];
        foreach ($fields as $field) {
            $diff[$field] = ($last->$field ?? 0) - ($first->$field ?? 0);
        }

        $temp = new UserStatSnapshots($diff);
        $diff['kd_ratio']            = $temp->kd_ratio;
        $diff['win_rate']            = $temp->win_rate;
        $diff['headshot_percentage'] = $temp->headshot_percentage;
        $diff['rating']              = $temp->rating;

        return response()->json([
            'weekly_stats' => $diff,
            'period' => [
                'start_date' => $first->snapshot_date,
                'end_date'   => $last->snapshot_date,
                'days_between' => $daysBetween,
            ]
        ]);
    }
}
