<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_stat_snapshots', function (Blueprint $table) {
            $table->integer('kdd')->default(0)->after('kd_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('user_stat_snapshots', function (Blueprint $table) {
            $table->dropColumn('kdd');
        });
    }
};