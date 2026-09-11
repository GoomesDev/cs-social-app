<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

/** Never load the local .env/config/route caches or connect to a persistent database. */
abstract class IsolatedDatabaseTestCase extends TestCase
{
    public function createApplication()
    {
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        return self::isolatedApplication();
    }

    public static function isolatedApplication(string $database = ':memory:')
    {
        if ($database !== ':memory:' && (! str_starts_with($database, sys_get_temp_dir().'/killfeed-auth-db-') || ! is_file($database))) {
            throw new \RuntimeException('Only isolated test databases are allowed');
        }
        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE'] as $key) {
            $value = sys_get_temp_dir().'/killfeed-auth-'.getmypid().'-'.$key.'.php';
            if (file_exists($value)) {
                throw new \RuntimeException('Unexpected test cache file');
            }
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/Fixtures/no-environment');
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($database) {
            $app['config']->set([
                'app.env' => 'testing', 'app.debug' => true,
                'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $database,
                'database.connections.sqlite.busy_timeout' => 5000,
                'database.connections.sqlite.url' => null,
                'cache.default' => 'array', 'session.driver' => 'array',
                'queue.default' => 'sync', 'logging.default' => 'null',
                'steam_auth.callback_url' => 'http://localhost/auth/steam/callback',
                'services.steam.api_key' => 'test-key',
                'services.steam.api_base' => 'https://api.steampowered.com',
                'services.steam.api_player_summary' => '/ISteamUser/GetPlayerSummaries/v0002/',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
