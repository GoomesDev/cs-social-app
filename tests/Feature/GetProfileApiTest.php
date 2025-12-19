<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Users;

class GetProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_profile_returns_user_data()
    {
        $user = Users::factory()->create();

        $response = $this->getJson('/api/get-profile/' . $user->id);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'id' => $user->id,
                     'name' => $user->name,
                 ]);
    }

    public function test_get_profile_returns_404_for_missing_user()
    {
        $response = $this->getJson('/api/get-profile/999999');
        $response->assertStatus(404)
                 ->assertJsonFragment([
                     'error' => 'Usuário não encontrado',
                 ]);
    }
}
