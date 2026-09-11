<?php

return [
    // Exact native return destinations. Never take an arbitrary redirect from the client.
    'redirect_uris' => ['killfeedmobile://auth/callback'],
    'callback_url' => env('STEAM_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/auth/steam/callback'),
    'attempt_seconds' => 600,
    'code_seconds' => 60,
    'token_minutes' => 43200, // 30 days; explicit expires_at on every issued token.
];
