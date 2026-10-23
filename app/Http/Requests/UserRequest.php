<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target
            ? $this->user()->can('update', $target)
            : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($target)],
            'role' => ['required', Rule::enum(Role::class)],
        ] + ($target ? [] : ['password' => ['required', 'string', Password::defaults()]]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'email.required' => "L'adresse email est obligatoire.",
            'email.email' => "L'adresse email n'est pas valide.",
            'email.unique' => 'Un compte utilise déjà cette adresse email.',
            'role.required' => 'Choisis un rôle.',
            'role.enum' => "Ce rôle n'est pas valide.",
            'password.required' => 'Le mot de passe temporaire est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ];
    }
}