<?php

namespace Database\Seeders;

use App\Models\ActivityFeedItem;
use Illuminate\Database\Seeder;

class ActivityFeedDemoCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $deleted = ActivityFeedItem::where('deduplication_key', 'like', ActivityFeedDemoSeeder::KEY_PREFIX.'%')->delete();

        $this->command?->info($deleted.' atividades sociais de demonstração foram removidas.');
    }
}
