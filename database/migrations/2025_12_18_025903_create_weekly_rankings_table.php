<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_rankings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('week', 8);

            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->decimal('rating', 5, 2);

            $table->timestamps();

            $table->unique(['week', 'user_id']);
            $table->index('week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_rankings');
    }
};
