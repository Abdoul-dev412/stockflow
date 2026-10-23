<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'stockflow:create-admin {--name= : Nom complet} {--email= : Adresse email}';

    protected $description = "Crée un compte administrateur (premier compte d'une installation vierge)";

    public function handle(AuditLogger $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Nom complet');
        $email = $this->option('email') ?: $this->ask('Adresse email');
        $password = $this->secret('Mot de passe (non affiché)');
        $confirmation = $this->secret('Confirme le mot de passe');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => mb_strtolower(trim((string) $email)),
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        DB::transaction(function () use ($data, $audit) {
            $user = new User([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->forceFill([
                'role' => Role::Admin,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ])->save();

            $audit->record(null, AuditAction::UserCreated, $user, [
                'snapshot' => ['name' => $user->name, 'email' => $user->email, 'role' => 'admin'],
            ]);
        });

        $this->info("Administrateur « {$data['email']} » créé.");

        return self::SUCCESS;
    }
}