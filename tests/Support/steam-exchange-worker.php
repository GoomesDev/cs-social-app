<?php

// Invoked only by the concurrency test with a temporary SQLite file and fixture credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = Tests\IsolatedDatabaseTestCase::isolatedApplication($argv[1]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
file_put_contents($argv[1].'.ready.'.$argv[2], 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[1].'.go')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
$request = Illuminate\Http\Request::create('/api/auth/steam/exchange', 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
], json_encode([
    'code' => str_repeat('1', 64),
    'code_verifier' => str_repeat('v', 64),
    'redirect_uri' => 'killfeedmobile://auth/callback',
]));
$response = $kernel->handle($request);
// Never print the credentials in the response, even in tests.
echo $response->getStatusCode();
$kernel->terminate($request, $response);
