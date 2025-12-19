<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Users>
 */
class UsersFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'steam_id' => $this->faker->unique()->numerify('7656119##########'),
            'username' => $this->faker->userName(),
            'profile_url' => $this->faker->url(),
            'display_name' => $this->faker->name(),
            'avatar' => $this->faker->imageUrl(),
            'is_active' => true,
            'last_sync_at' => now(),
        ];
    }
}
