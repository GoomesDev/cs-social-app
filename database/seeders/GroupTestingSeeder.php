<?php

namespace Database\Seeders;

use App\Models\Users;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GroupTestingSeeder extends Seeder
{
    public const USERNAME_PREFIX = '__killfeed_group_test_';

    public const PLAYERS = [
        ['steam_id' => '76561199990000001', 'display_name' => 'Clutch Cobra'],
        ['steam_id' => '76561199990000002', 'display_name' => 'Bombsite Boss'],
        ['steam_id' => '76561199990000003', 'display_name' => 'Headshot Hero'],
        ['steam_id' => '76561199990000004', 'display_name' => 'Smoke Wizard'],
        ['steam_id' => '76561199990000005', 'display_name' => 'Entry Eagle'],
        ['steam_id' => '76561199990000006', 'display_name' => 'Flash Master'],
        ['steam_id' => '76561199990000007', 'display_name' => 'Pixel Hunter'],
        ['steam_id' => '76561199990000008', 'display_name' => 'Retake King'],
    ];

    public function run(): void
    {
        $owner = self::owner();

        DB::transaction(function () use ($owner) {
            foreach (self::PLAYERS as $index => $player) {
                $testUser = Users::updateOrCreate(
                    ['steam_id' => $player['steam_id']],
                    [
                        'username' => self::USERNAME_PREFIX.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                        'display_name' => $player['display_name'],
                        'profile_url' => 'https://steamcommunity.com/profiles/'.$player['steam_id'],
                        'avatar' => 'https://api.dicebear.com/9.x/bottts/png?seed='.rawurlencode($player['display_name']),
                        'is_active' => true,
                        'last_sync_at' => now(),
                    ],
                );

                foreach ([[$owner->id, $testUser->id], [$testUser->id, $owner->id]] as [$userId, $friendId]) {
                    DB::table('friends')->updateOrInsert(
                        ['user_id' => $userId, 'friend_id' => $friendId],
                        ['created_at' => now(), 'is_test_data' => true],
                    );
                }
            }

            $owner->forceFill(['friends_synced_at' => now()])->save();
        });

        $this->command?->info('8 usuários de teste foram adicionados como amigos de '.$owner->display_name.'.');
    }

    public static function owner(): Users
    {
        $configuredSteamId = env('GROUP_TEST_OWNER_STEAM_ID');
        if (is_string($configuredSteamId) && $configuredSteamId !== '') {
            return Users::where('steam_id', $configuredSteamId)->firstOrFail();
        }

        $owners = Users::where(fn ($query) => $query->whereNull('username')
            ->orWhere('username', 'not like', self::USERNAME_PREFIX.'%'))->get();

        if ($owners->count() !== 1) {
            throw new RuntimeException('Defina GROUP_TEST_OWNER_STEAM_ID para escolher o perfil que receberá os amigos de teste.');
        }

        return $owners->first();
    }
}
