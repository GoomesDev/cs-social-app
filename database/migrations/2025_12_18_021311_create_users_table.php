<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {

            $table->bigIncrements('id');
            $table->string('steam_id', 20)->unique();
            $table->string('username', 50)->nullable();
            $table->string('display_name', 100);
            $table->text('avatar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('display_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
