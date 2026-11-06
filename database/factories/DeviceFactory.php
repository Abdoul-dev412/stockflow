<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => hash('sha256', Str::random(40)),
            'token_expires_at' => now()->addDays(7),
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $token)]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinute()]);
    }
}