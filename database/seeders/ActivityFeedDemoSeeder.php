<?php

namespace Database\Seeders;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityFeedDemoSeeder extends Seeder
{
    public const KEY_PREFIX = 'demo-feed:v1:';

    public function run(): void
    {
        $this->call(GroupTestingSeeder::class);

        $owner = GroupTestingSeeder::owner();
        $actors = Users::where('username', 'like', GroupTestingSeeder::USERNAME_PREFIX.'%')
            ->orderBy('username')->get()->prepend($owner)->values();

        DB::transaction(function () use ($actors) {
            foreach (range(0, 31) as $index) {
                $actor = $actors[$index % $actors->count()];
                $type = $this->type($index);
                $occurredAt = now()->subHours($index * 3);
                $period = str_starts_with($type, 'daily_') ? 'daily' : 'weekly';
                $reference = $period === 'daily'
                    ? CarbonImmutable::instance($occurredAt)->toDateString()
                    : CarbonImmutable::instance($occurredAt)->startOfWeek(CarbonImmutable::SUNDAY)->toDateString();

                ActivityFeedItem::updateOrCreate(
                    ['deduplication_key' => self::KEY_PREFIX.$index],
                    [
                        'actor_id' => $actor->id,
                        'type' => $type,
                        'period' => $period,
                        'reference_date' => $reference,
                        'payload' => $this->payload($type, $index),
                        'occurred_at' => $occurredAt,
                    ],
                );
            }
        });

        $this->command?->info('32 atividades sociais de demonstração foram criadas.');
    }

    private function type(int $index): string
    {
        return [
            'weekly_rating_highlight',
            'daily_kills_milestone',
            'weekly_headshots_milestone',
            'daily_negative_kd',
        ][$index % 4];
    }

    private function payload(string $type, int $index): array
    {
        return match ($type) {
            'daily_kills_milestone' => [
                'kills' => [58, 108, 157, 205][intdiv($index, 4) % 4],
                'milestone' => [50, 100, 150, 200][intdiv($index, 4) % 4],
                'matches' => 4 + ($index % 5),
            ],
            'daily_negative_kd' => [
                'kills' => 22 + ($index % 8),
                'deaths' => 36 + ($index % 10),
                'difference' => 14 + (($index % 10) - ($index % 8)),
                'matches' => 3 + ($index % 4),
            ],
            'weekly_headshots_milestone' => [
                'headshots' => [29, 58, 112, 163][intdiv($index, 4) % 4],
                'milestone' => [25, 50, 100, 150][intdiv($index, 4) % 4],
                'headshot_percentage' => round(38.5 + ($index * 0.7), 2),
            ],
            'weekly_rating_highlight' => [
                'rating' => round(1.21 + (($index % 8) * 0.04), 2),
                'matches' => 5 + ($index % 5),
                'kills' => 74 + ($index * 2),
                'deaths' => 48 + $index,
            ],
        };
    }
}
