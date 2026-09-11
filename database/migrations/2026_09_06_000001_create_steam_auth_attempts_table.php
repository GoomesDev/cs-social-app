<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steam_auth_attempts', function (Blueprint $table) {
            $table->char('state_hash', 64)->primary();
            $table->char('browser_hash', 64);
            $table->string('client_state', 128);
            $table->string('redirect_uri');
            $table->char('code_challenge', 43);
            $table->text('callback_url');
            $table->timestamp('expires_at')->index();
            $table->timestamp('callback_used_at')->nullable();
            $table->char('nonce_hash', 64)->nullable()->unique();
            $table->char('code_hash', 64)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steam_auth_attempts');
    }
};
