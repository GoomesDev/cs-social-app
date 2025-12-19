<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Users;
use Illuminate\Support\Facades\Http;
use App\Models\UserStatSnapshots;

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
        $createSnapshot = new UserStatSnapshots();
        try {
            $createSnapshot::create([
                'user_id' => $userId,
                'snapshot_date' => now(),
                'matches' => $stats['total_matches_played'] ?? 0,
                'wins' => $stats['total_matches_won'] ?? 0,
                'losses' => $stats['total_matches_played'] - ($stats['total_matches_won'] ?? 0),
                'kills' => $stats['total_kills'] ?? 0,
                'deaths' => $stats['total_deaths'] ?? 0,
                'mvp' => $stats['total_mvps'] ?? 0,
                'bombs_planted' => $stats['total_planted_bombs'] ?? 0,
                'bombs_defused' => $stats['total_defused_bombs'] ?? 0,
                'headshots' => $stats['total_kills_headshot'] ?? 0,
                'headshot_percentage' => isset($stats['total_kills']) && $stats['total_kills'] > 0 ? round(($stats['total_kills_headshot'] / $stats['total_kills']) * 100, 2) : 0,
                'kd_ratio' => $stats['total_kills'] > 0 ? round($stats['total_kills'] / max(1, $stats['total_deaths']), 2) : 0,
                'rating' => $stats['total_kills'] > 0 ? round(($stats['total_kills'] / max(1, $stats['total_deaths'])), 2) : 0,
                'win_rate' => $stats['total_matches_played'] > 0 ? round(($stats['total_matches_won'] / $stats['total_matches_played']) * 100, 2) : 0,
            ]);
        } catch (\Exception $e) {
            \Log::error('Erro ao criar snapshot: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao criar snapshot'], 500);
        }

        return response()->json(['message' => 'Snapshot criado com sucesso']);
    }
}
