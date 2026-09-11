<?php

namespace App\Services;

use App\Models\UserStatSnapshots;
use Carbon\CarbonImmutable;

class PeriodStats
{
    public function forUser(int $userId, CarbonImmutable $reference, string $type): array
    {
        $start = $type === 'daily' ? $reference : $reference->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $type === 'daily' ? $reference : $start->addDays(6);
        // Never use snapshots later than the requested period or later than today.
        $cutoff = $end->min(CarbonImmutable::today())->toDateString();
        $query = UserStatSnapshots::where('user_id', $userId)
            ->whereDate('snapshot_date', '<=', $cutoff)->orderByDesc('snapshot_date');
        $latest = (clone $query)->first();
        $response = [
            $type.'_stats' => null,
            'status' => $latest ? 'insufficient_data' : 'empty',
            'requested_period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'period' => null,
            'latest_snapshot' => $latest ? [
                ...$latest->only([
                    'matches', 'wins', 'losses', 'rounds', 'kills', 'deaths', 'mvps',
                    'bombs_planted', 'bombs_defused', 'headshots', 'kd_ratio', 'win_rate',
                    'headshot_percentage', 'rating', 'impact_score', 'kdd',
                ]),
                'snapshot_date' => $latest->snapshot_date->toDateString(),
            ] : null,
        ];

        // Read in batches, without storing derived copies that could become outdated.
        $first = null;
        $last = null;
        foreach ($query->lazy(200) as $snapshot) {
            if ($type === 'daily') {
                if ($last && (int) $snapshot->snapshot_date->diffInDays($last->snapshot_date) === 1) {
                    $stats = $this->periodStats($snapshot, $last);
                    if ($stats !== null) {
                        return $this->withPeriod($response, $type, $snapshot, $last, $stats, $start);
                    }
                }
                $last = $snapshot;

                continue;
            }

            $week = $snapshot->snapshot_date->copy()->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();
            $lastWeek = $last?->snapshot_date->copy()->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();
            if ($last && $week !== $lastWeek) {
                $stats = $first->id !== $last->id ? $this->periodStats($first, $last) : null;
                if ($stats !== null) {
                    return $this->withPeriod($response, $type, $first, $last, $stats, $start);
                }
                $last = null;
            }
            $last ??= $snapshot;
            $first = $snapshot;
        }

        if ($type === 'weekly' && $first && $first->id !== $last->id) {
            $stats = $this->periodStats($first, $last);
            if ($stats !== null) {
                return $this->withPeriod($response, $type, $first, $last, $stats, $start);
            }
        }

        return $response;
    }

    private function withPeriod(array $response, string $type, UserStatSnapshots $first, UserStatSnapshots $last, array $stats, CarbonImmutable $requestedStart): array
    {
        $actualStart = $type === 'daily'
            ? $last->snapshot_date->toDateString()
            : $last->snapshot_date->copy()->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        return array_replace($response, [
            $type.'_stats' => $stats,
            'status' => $actualStart === $requestedStart->toDateString() ? 'available' : 'fallback',
            'period' => [
                'start_date' => $first->snapshot_date->toDateString(),
                'end_date' => $last->snapshot_date->toDateString(),
                'days_between' => (int) $first->snapshot_date->diffInDays($last->snapshot_date),
            ],
        ]);
    }

    private function periodStats(UserStatSnapshots $previous, UserStatSnapshots $current): ?array
    {
        $fields = [
            'kills', 'deaths', 'mvps', 'bombs_planted', 'bombs_defused',
            'headshots', 'matches', 'wins', 'losses', 'rounds',
        ];

        $diff = [];
        foreach ($fields as $field) {
            $diff[$field] = $current->$field - $previous->$field;
            if ($diff[$field] < 0) {
                return null;
            }
        }

        $diff['kd_ratio'] = ScoreCalculator::calculateKDRatio($diff['kills'], $diff['deaths']);
        $diff['win_rate'] = ScoreCalculator::calculateWinRate($diff['wins'], $diff['matches']);
        $diff['headshot_percentage'] = ScoreCalculator::calculateHeadshotPercentage($diff['headshots'], $diff['kills']);
        $diff['rating'] = ScoreCalculator::calculateRating(
            $diff['kills'], $diff['deaths'], $diff['mvps'], $diff['headshots'], $diff['rounds']
        );
        $diff['impact_score'] = ScoreCalculator::calculateImpactScore(
            $diff['kd_ratio'], $diff['headshot_percentage'], $diff['win_rate'], $diff['mvps'], $diff['matches']
        );
        $diff['kdd'] = ScoreCalculator::calculateKDD($diff['kills'], $diff['deaths']);

        return $diff;
    }
}
