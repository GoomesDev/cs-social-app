<?php

$integerList = static function (string $key, string $default): array {
    $values = array_filter(array_map('trim', explode(',', (string) env($key, $default))), 'strlen');
    $values = array_values(array_unique(array_filter(array_map('intval', $values), fn ($value) => $value > 0)));
    sort($values);

    return $values;
};

return [
    'daily_kill_milestones' => $integerList('FEED_DAILY_KILL_MILESTONES', '50,100,150,200'),
    'daily_negative_kd_difference' => (int) env('FEED_DAILY_NEGATIVE_KD_DIFFERENCE', 10),
    'weekly_headshot_milestones' => $integerList('FEED_WEEKLY_HEADSHOT_MILESTONES', '25,50,100,150'),
    'weekly_rating_minimum' => (float) env('FEED_WEEKLY_RATING_MINIMUM', 1.20),
    'weekly_rating_minimum_matches' => (int) env('FEED_WEEKLY_RATING_MINIMUM_MATCHES', 3),
    'retention_days' => (int) env('FEED_RETENTION_DAYS', 90),
];
