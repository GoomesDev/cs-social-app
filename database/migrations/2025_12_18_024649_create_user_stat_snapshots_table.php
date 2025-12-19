<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stat_snapshots', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->date('snapshot_date')->index();

            $table->integer('matches')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('kills')->default(0);
            $table->integer('deaths')->default(0);
            $table->integer('mvps')->default(0);
            $table->integer('bombs_planted')->default(0);
            $table->integer('bombs_defused')->default(0);
            $table->integer('headshots')->default(0);
            $table->decimal('headshot_percentage', 5, 2)->default(0);

            $table->decimal('kd_ratio', 6, 2)->default(0);
            $table->decimal('rating', 6, 2)->default(0);
            $table->decimal('win_rate', 6, 2)->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stat_snapshots');
    }
};
