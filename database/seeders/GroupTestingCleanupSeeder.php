<?php

namespace Database\Seeders;

use App\Models\Users;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GroupTestingCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $deleted = DB::transaction(fn () => Users::where(
            'username', 'like', GroupTestingSeeder::USERNAME_PREFIX.'%'
        )->delete());

        $this->command?->info($deleted.' usuários de teste e seus vínculos foram removidos.');
    }
}
