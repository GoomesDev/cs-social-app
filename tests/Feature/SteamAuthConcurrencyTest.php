<?php

namespace Tests\Feature;

use App\Models\Users;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedDatabaseTestCase;

class SteamAuthConcurrencyTest extends IsolatedDatabaseTestCase
{
    public function test_two_concurrent_exchanges_create_exactly_one_token(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required to run independent HTTP kernels');
        }
        $database = tempnam(sys_get_temp_dir(), 'killfeed-auth-db-');
        $processes = [];
        try {
            config(['database.connections.sqlite.database' => $database]);
            DB::purge('sqlite');
            $this->artisan('migrate', ['--database' => 'sqlite'])->assertExitCode(0);
            $user = Users::factory()->create();
            DB::table('steam_auth_attempts')->insert([
                'state_hash' => hash('sha256', 'test-state'),
                'browser_hash' => hash('sha256', 'test-browser'),
                'client_state' => str_repeat('s', 32),
                'redirect_uri' => 'killfeedmobile://auth/callback',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
                'callback_url' => 'http://localhost/auth/steam/callback',
                'expires_at' => now()->addMinutes(10), 'callback_used_at' => now(),
                'code_hash' => hash('sha256', str_repeat('1', 64)),
                'code_expires_at' => now()->addMinute(), 'user_id' => $user->id,
            ]);
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/steam-exchange-worker.php'), $database, (string) $i],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            while (! file_exists($database.'.ready.0') || ! file_exists($database.'.ready.1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach the concurrency barrier');
                }
                usleep(10000);
            }
            touch($database.'.go');
            $statuses = [];
            foreach ($processes as [$process, $pipes]) {
                $statuses[] = (int) stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $stderr);
            }
            $processes = [];
            sort($statuses);
            $this->assertSame(200, $statuses[0]);
            // SQLite may reject a lock upgrade; PostgreSQL/MySQL serialize the row lock.
            $this->assertContains($statuses[1], [401, 503]);
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->postJson('/api/auth/steam/exchange', [
                'code' => str_repeat('1', 64), 'code_verifier' => str_repeat('v', 64),
                'redirect_uri' => 'killfeedmobile://auth/callback',
            ])->assertUnauthorized();
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            DB::purge('sqlite');
            foreach (glob($database.'*') as $file) {
                unlink($file);
            }
        }
    }
}
