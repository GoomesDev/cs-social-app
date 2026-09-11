<?php

namespace App\Console\Commands;

use App\Models\ActivityFeedItem;
use Illuminate\Console\Command;

class PruneActivityFeed extends Command
{
    protected $signature = 'activity-feed:prune';

    protected $description = 'Remove social activity feed items older than the configured retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('activity_feed.retention_days', 90));
        $deleted = ActivityFeedItem::where('occurred_at', '<', now()->subDays($days))->delete();
        $this->info("Removed {$deleted} expired activity feed item(s).");

        return self::SUCCESS;
    }
}
