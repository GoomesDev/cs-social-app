<?php

namespace Database\Seeders;

use App\Models\Users;
use App\Services\ScoreCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GroupTestingSnapshotsSeeder extends Seeder
{
    public function run(): void
    {
        $users = Users::where('username', 'like', GroupTestingSeeder::USERNAME_PREFIX.'%')
            ->orderBy('username')->get();

        if ($users->count() !== count(GroupTestingSeeder::PLAYERS)) {
            throw new RuntimeException('Execute GroupTestingSeeder antes de gerar os snapshots de teste.');
        }

        DB::transaction(function () use ($users) {
            DB::table('user_stat_snapshots')->whereIn('user_id', $users->pluck('id'))->delete();

            foreach ($users as $playerIndex => $user) {
                $totals = $this->baseline($playerIndex);

                for ($day = 14; $day >= 0; $day--) {
                    $date = CarbonImmutable::today()->subDays($day);
                    $totals = $this->advance($totals, $playerIndex, 14 - $day);

                    DB::table('user_stat_snapshots')->insert([
                        'user_id' => $user->id,
                        'snapshot_date' => $date->toDateString(),
                        ...$totals,
                        ...$this->metrics($totals),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->command?->info('120 snapshots foram gerados para os 8 perfis de teste.');
    }

    private function baseline(int $index): array
    {
        $matches = 240 + ($index * 35);
        $wins = (int) floor($matches * (0.46 + ($index * 0.018)));
        $rounds = $matches * 21;
        $kills = (int) floor($rounds * (0.55 + ($index * 0.025)));
        $deaths = (int) floor($rounds * (0.64 - ($index * 0.012)));

        return [
            'matches' => $matches,
            'wins' => $wins,
            'losses' => $matches - $wins,
            'rounds' => $rounds,
            'kills' => $kills,
            'deaths' => $deaths,
            'mvps' => (int) floor($matches * (0.18 + ($index * 0.02))),
            'bombs_planted' => (int) floor($matches * 0.42),
            'bombs_defused' => (int) floor($matches * (0.12 + ($index * 0.01))),
            'headshots' => (int) floor($kills * (0.34 + ($index * 0.025))),
        ];
    }

    private function advance(array $totals, int $index, int $day): array
    {
        $matches = 1 + (($index + $day) % 4);
        $wins = min($matches, (($index * 2) + $day) % ($matches + 1));
        $rounds = ($matches * 19) + (($index + $day) % 8);
        $kills = (int) round($rounds * (0.55 + ($index * 0.035)));
        $deaths = (int) round($rounds * (0.68 - ($index * 0.018)));

        foreach ([
            'matches' => $matches,
            'wins' => $wins,
            'losses' => $matches - $wins,
            'rounds' => $rounds,
            'kills' => $kills,
            'deaths' => $deaths,
            'mvps' => max(1, (int) floor($matches * (0.45 + ($index * 0.08)))),
            'bombs_planted' => $matches + (($index + $day) % 3),
            'bombs_defused' => ($index + $day) % 3,
            'headshots' => (int) floor($kills * (0.35 + ($index * 0.035))),
        ] as $field => $increment) {
            $totals[$field] += $increment;
        }

        return $totals;
    }

    private function metrics(array $totals): array
    {
        $kd = ScoreCalculator::calculateKDRatio($totals['kills'], $totals['deaths']);
        $headshots = ScoreCalculator::calculateHeadshotPercentage($totals['headshots'], $totals['kills']);
        $winRate = ScoreCalculator::calculateWinRate($totals['wins'], $totals['matches']);

        return [
            'kd_ratio' => $kd,
            'kdd' => ScoreCalculator::calculateKDD($totals['kills'], $totals['deaths']),
            'headshot_percentage' => $headshots,
            'win_rate' => $winRate,
            'rating' => ScoreCalculator::calculateRating(
                $totals['kills'], $totals['deaths'], $totals['mvps'], $totals['headshots'], $totals['rounds']
            ),
            'impact_score' => ScoreCalculator::calculateImpactScore(
                $kd, $headshots, $winRate, $totals['mvps'], $totals['matches']
            ),
        ];
    }
}
