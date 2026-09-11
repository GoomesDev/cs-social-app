<?php

namespace App\Services;

use App\Models\Users;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SteamFriends
{
    public function forUser(Users $user, int $page = 1): array
    {
        $result = $this->allForUser($user);
        $perPage = 25;
        $total = count($result['data']);

        $result['data'] = array_slice($result['data'], ($page - 1) * $perPage, $perPage);
        $result['meta'] = [
            ...$result['meta'],
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'has_more' => $page * $perPage < $total,
        ];

        return $result;
    }

    private function allForUser(Users $user): array
    {
        if (! is_string(config('services.steam.api_key')) || config('services.steam.api_key') === '') {
            throw new RuntimeException('steam_not_configured');
        }

        $steam = Cache::remember('steam-friends:v1:'.$user->steam_id, 300, fn () => $this->fetch($user->steam_id));
        // Resolve membership on every request, so new registrations are visible even with cached Steam data.
        $members = Users::whereIn('steam_id', array_column($steam['friends'], 'steam_id'))
            ->where('is_active', true)->get(['id', 'steam_id'])->keyBy('steam_id');
        $friends = array_map(function ($friend) use ($members) {
            $member = $members->get($friend['steam_id']);

            return [...$friend, 'uses_killfeed' => $member !== null, 'user_id' => $member?->id];
        }, $steam['friends']);
        usort($friends, fn ($a, $b) => ($b['uses_killfeed'] <=> $a['uses_killfeed'])
            ?: strcasecmp($a['display_name'] ?? $a['steam_id'], $b['display_name'] ?? $b['steam_id'])
            ?: strcmp($a['steam_id'], $b['steam_id']));

        return [
            'data' => $friends,
            'meta' => [
                'total' => count($friends),
                'killfeed_count' => count(array_filter($friends, fn ($friend) => $friend['uses_killfeed'])),
                'fetched_at' => $steam['fetched_at'],
                'profiles_complete' => $steam['profiles_complete'],
            ],
        ];
    }

    public function syncForUser(Users $user): array
    {
        $result = $this->allForUser($user);
        $friendIds = array_values(array_filter(array_column($result['data'], 'user_id')));
        $syncedAt = now();

        DB::transaction(function () use ($user, $friendIds, $syncedAt) {
            $query = DB::table('friends')->where('user_id', $user->id)->where('is_test_data', false);

            if ($friendIds === []) {
                $query->delete();
            } else {
                $query->whereNotIn('friend_id', $friendIds)->delete();
                DB::table('friends')->insertOrIgnore(array_map(fn ($friendId) => [
                    'user_id' => $user->id,
                    'friend_id' => $friendId,
                    'created_at' => $syncedAt,
                    'is_test_data' => false,
                ], $friendIds));
            }

            DB::table('users')->where('id', $user->id)->update([
                'friends_synced_at' => $syncedAt,
            ]);
        });

        $result['meta']['synced_count'] = count($friendIds);
        $result['meta']['friends_synced_at'] = $syncedAt->toIso8601String();

        return $result;
    }

    private function fetch(string $steamId): array
    {
        $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()
            ->get('https://api.steampowered.com/ISteamUser/GetFriendList/v1/', [
                'key' => config('services.steam.api_key'), 'steamid' => $steamId, 'relationship' => 'friend',
            ]);
        if ($response->status() === 401 || $response->status() === 403) {
            // Steam may deny access because of privacy or API access restrictions.
            throw new RuntimeException('steam_friends_unavailable');
        }
        $list = $response->json('friendslist.friends');
        if (! $response->successful() || ! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException('steam_unavailable');
        }
        $friends = [];
        foreach ($list as $friend) {
            if (! is_array($friend) || ! is_string($friend['steamid'] ?? null)
                || ! preg_match('/\A[0-9]{17}\z/', $friend['steamid'])
                || ($friend['relationship'] ?? null) !== 'friend'
                || ! is_int($friend['friend_since'] ?? null) || $friend['friend_since'] < 0) {
                throw new RuntimeException('steam_unavailable');
            }
            if ($friend['steamid'] !== $steamId) {
                $friends[$friend['steamid']] = [
                    'steam_id' => $friend['steamid'], 'friend_since' => $friend['friend_since'],
                    'display_name' => null, 'avatar' => null, 'profile_url' => null,
                ];
            }
        }
        // Steam GetPlayerSummaries accepts at most 100 Steam IDs per request.
        foreach (array_chunk(array_keys($friends), 100) as $ids) {
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/', [
                    'key' => config('services.steam.api_key'), 'steamids' => implode(',', $ids),
                ]);
            $players = $response->json('response.players');
            if (! $response->successful() || ! is_array($players) || ! array_is_list($players)) {
                throw new RuntimeException('steam_unavailable');
            }
            foreach ($players as $player) {
                if (! is_array($player) || ! is_string($player['steamid'] ?? null)) {
                    throw new RuntimeException('steam_unavailable');
                }
                $id = $player['steamid'];
                if (! isset($friends[$id])) {
                    continue;
                }
                $friends[$id]['display_name'] = is_string($player['personaname'] ?? null) ? $player['personaname'] : null;
                $friends[$id]['avatar'] = $this->httpsUrl($player['avatarfull'] ?? null);
                $friends[$id]['profile_url'] = $this->httpsUrl($player['profileurl'] ?? null);
            }
        }

        return [
            'friends' => array_values($friends),
            'fetched_at' => now()->toIso8601String(),
            'profiles_complete' => count(array_filter($friends, fn ($friend) => $friend['display_name'] === null)) === 0,
        ];
    }

    private function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
            && parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
    }
}
