<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('period', 10)->nullable();
            $table->date('reference_date')->nullable();
            $table->json('payload');
            $table->string('deduplication_key', 191)->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['actor_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_feed_items');
    }
};
