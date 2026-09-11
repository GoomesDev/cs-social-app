<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class GroupsApiTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    private function login(?Users $user = null): Users
    {
        $user ??= Users::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    public function test_owner_creates_group_with_killfeed_friends_and_is_automatically_a_member(): void
    {
        $owner = $this->login();
        $friend = Users::factory()->create();
        $owner->friends()->attach($friend->id);

        $this->postJson('/api/groups', ['name' => 'Clutch Squad', 'icon' => 'crosshair', 'member_ids' => [$friend->id]])
            ->assertCreated()->assertJsonPath('data.name', 'Clutch Squad')
            ->assertJsonPath('data.icon', 'crosshair')
            ->assertJsonPath('data.is_owner', true)->assertJsonPath('data.members_count', 2);

        $this->assertDatabaseHas('group_members', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('group_members', ['user_id' => $friend->id]);
    }

    public function test_creation_without_icon_uses_target_and_existing_model_creation_gets_database_default(): void
    {
        $owner = $this->login();

        $this->postJson('/api/groups', ['name' => 'Default Icon'])
            ->assertCreated()->assertJsonPath('data.icon', 'target');

        $existingStyleGroup = Group::create(['owner_id' => $owner->id, 'name' => 'Before Icons']);
        $this->assertSame('target', $existingStyleGroup->fresh()->icon);
    }

    public function test_creation_rejects_icon_outside_allowlist(): void
    {
        $this->login();

        $this->postJson('/api/groups', ['name' => 'Invalid Icon', 'icon' => '<svg>'])
            ->assertUnprocessable()->assertJsonValidationErrors('icon');
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_group_rejects_users_who_are_not_active_killfeed_friends(): void
    {
        $owner = $this->login();
        $stranger = Users::factory()->create();

        $this->postJson('/api/groups', ['name' => 'Clutch Squad', 'member_ids' => [$stranger->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('member_ids');
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_member_can_view_group_but_cannot_manage_it(): void
    {
        $owner = Users::factory()->create();
        $member = $this->login();
        $other = Users::factory()->create();
        $owner->friends()->attach([$member->id, $other->id]);
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach([$owner->id, $member->id]);

        $this->getJson("/api/groups/{$group->id}")->assertOk()->assertJsonPath('data.is_owner', false);
        $this->postJson("/api/groups/{$group->id}/members", ['user_id' => $other->id])->assertForbidden();
        $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();
    }

    public function test_owner_can_add_and_remove_a_friend(): void
    {
        $owner = $this->login();
        $friend = Users::factory()->create();
        $owner->friends()->attach($friend->id);
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->postJson("/api/groups/{$group->id}/members", ['user_id' => $friend->id])
            ->assertOk()->assertJsonPath('data.members_count', 2)->assertJsonPath('data.icon', 'target');
        $this->deleteJson("/api/groups/{$group->id}/members/{$friend->id}")
            ->assertOk()->assertJsonPath('data.members_count', 1)->assertJsonPath('data.icon', 'target');
    }

    public function test_non_member_cannot_view_group(): void
    {
        $owner = Users::factory()->create();
        $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->getJson("/api/groups/{$group->id}")->assertNotFound();
    }

    public function test_owner_cannot_be_removed(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->deleteJson("/api/groups/{$group->id}/members/{$owner->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_list_only_contains_groups_the_user_belongs_to(): void
    {
        $user = $this->login();
        $visible = Group::create(['owner_id' => $user->id, 'name' => 'Visible']);
        $visible->members()->attach($user->id);
        $hiddenOwner = Users::factory()->create();
        $hidden = Group::create(['owner_id' => $hiddenOwner->id, 'name' => 'Hidden']);
        $hidden->members()->attach($hiddenOwner->id);

        $this->getJson('/api/groups')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)->assertJsonPath('data.0.icon', 'target');
    }

    public function test_candidates_only_include_active_synced_friends(): void
    {
        $owner = $this->login();
        $active = Users::factory()->create();
        $inactive = Users::factory()->create(['is_active' => false]);
        Users::factory()->create();
        $owner->friends()->attach([$active->id, $inactive->id]);

        $this->getJson('/api/groups/candidates')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_owner_can_delete_group_and_its_memberships(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Temporary']);
        $group->members()->attach($owner->id);

        $this->deleteJson("/api/groups/{$group->id}")->assertNoContent();
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id]);
    }

    public function test_owner_can_update_only_name(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Old Name', 'icon' => 'shield']);
        $group->members()->attach($owner->id);

        $this->patchJson("/api/groups/{$group->id}", ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.icon', 'shield');
    }

    public function test_owner_can_update_only_icon(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->patchJson("/api/groups/{$group->id}", ['icon' => 'crown'])
            ->assertOk()->assertJsonPath('data.name', 'Lobby')->assertJsonPath('data.icon', 'crown');
    }

    public function test_owner_can_update_name_and_icon_together(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->patchJson("/api/groups/{$group->id}", ['name' => 'Major Winners', 'icon' => 'trophy'])
            ->assertOk()->assertJsonPath('data.name', 'Major Winners')->assertJsonPath('data.icon', 'trophy');
    }

    public function test_update_requires_at_least_one_supported_field_and_valid_icon(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->patchJson("/api/groups/{$group->id}", [])->assertUnprocessable()
            ->assertJsonValidationErrors('group');
        $this->patchJson("/api/groups/{$group->id}", ['icon' => 'react-component'])
            ->assertUnprocessable()->assertJsonValidationErrors('icon');
    }

    public function test_common_member_cannot_update_group(): void
    {
        $owner = Users::factory()->create();
        $member = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach([$owner->id, $member->id]);

        $this->patchJson("/api/groups/{$group->id}", ['icon' => 'flame'])->assertForbidden();
        $this->assertSame('target', $group->fresh()->icon);
    }

    public function test_non_member_cannot_update_group(): void
    {
        $owner = Users::factory()->create();
        $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Lobby']);
        $group->members()->attach($owner->id);

        $this->patchJson("/api/groups/{$group->id}", ['icon' => 'flame'])->assertNotFound();
        $this->assertSame('target', $group->fresh()->icon);
    }

    public function test_show_serializes_persisted_icon(): void
    {
        $owner = $this->login();
        $group = Group::create(['owner_id' => $owner->id, 'name' => 'Awards', 'icon' => 'award']);
        $group->members()->attach($owner->id);

        $this->getJson("/api/groups/{$group->id}")->assertOk()->assertJsonPath('data.icon', 'award');
    }

    public function test_group_routes_require_authentication(): void
    {
        $this->getJson('/api/groups')->assertUnauthorized();
        $this->postJson('/api/groups', ['name' => 'Lobby'])->assertUnauthorized();
    }
}
