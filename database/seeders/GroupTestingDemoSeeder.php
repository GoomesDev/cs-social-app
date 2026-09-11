<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GroupTestingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GroupTestingSeeder::class,
            GroupTestingSnapshotsSeeder::class,
            ActivityFeedDemoSeeder::class,
        ]);
    }
}
