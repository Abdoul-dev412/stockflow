<?php

namespace Database\Seeders;

use App\Models\User;
use App\Enums\Role;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    // public function run(): void
    // {
    //     // User::factory(10)->create();

    //     User::factory()->create([
    //         'name' => 'Test User',
    //         'email' => 'test@example.com',
    //     ]);
    public function run(): void
{
    User::factory()->create([
        'name' => 'Admin Démo',
        'email' => 'admin@stockflow.test',
        'role' => Role::Admin,
    ]);

    User::factory()->create([
        'name' => 'Gestionnaire Démo',
        'email' => 'gestionnaire@stockflow.test',
        'role' => Role::Manager,
    ]);
}
}
