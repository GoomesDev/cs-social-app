<?php

namespace App\Jobs;

use App\Models\UserStatSnapshots;
use App\Services\ScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecalculateSnapshotScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Optionally scope recalculation to a single snapshot.
     */
    public function __construct(
        public readonly ?int $snapshotId = null
    ) {}

    public function handle(): void
    {
        $query = UserStatSnapshots::query();

        if ($this->snapshotId) {
            $query->where('id', $this->snapshotId);
        }

        $query->chunkById(200, function ($snapshots) {
            foreach ($snapshots as $snapshot) {
                try {
                    $rating = ScoreCalculator::calculateRating(
                        kills:      $snapshot->kills,
                        deaths:     $snapshot->deaths,
                        mvps:       $snapshot->mvps,
                        headshots:  $snapshot->headshots,
                        rounds:     $snapshot->rounds,
                    );

                    $impactScore = ScoreCalculator::calculateImpactScore(
                        kdRatio:             $snapshot->kd_ratio,
                        headshotPercentage:  $snapshot->headshot_percentage,
                        winRate:             $snapshot->win_rate,
                        mvps:                $snapshot->mvps,
                        matches:             $snapshot->matches,
                    );

                    $snapshot->updateOrCreate([
                        'rating'       => $rating,
                        'impact_score' => $impactScore,
                    ]);

                    RecalculateSnapshotScoresJob::dispatch($snapshot->id);
                } catch (\Throwable $e) {
                    Log::error('RecalculateSnapshotScoresJob failed for snapshot ' . $snapshot->id, [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}