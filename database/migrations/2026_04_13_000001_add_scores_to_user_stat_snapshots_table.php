<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_stat_snapshots', function (Blueprint $table) {
            $table->decimal('rating', 6, 2)->default(0)->change();
            $table->decimal('impact_score', 6, 2)->default(0)->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('user_stat_snapshots', function (Blueprint $table) {
            $table->dropColumn('impact_score');
        });
    }
};