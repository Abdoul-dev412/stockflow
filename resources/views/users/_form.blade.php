@csrf
@php
    $editing = isset($user);
    $isSelf = $editing && $user->is(auth()->user());
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-input-label for="name" value="Nom complet" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
            :value="old('name', $user->name ?? '')" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="email" value="Adresse email" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
            :value="old('email', $user->email ?? '')" required />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="role" value="Rôle" />
        @if ($isSelf)
            <input type="hidden" name="role" value="{{ $user->role->value }}">
            <p class="mt-2 text-sm text-gray-700">
                {{ $user->role->label() }}
                <span class="text-gray-500">(tu ne peux pas modifier ton propre rôle)</span>
            </p>
        @else
            <select id="role" name="role" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}"
                        @selected(old('role', isset($user) ? $user->role->value : 'manager') === $role->value)>
                        {{ $role->label() }}
                    </option>
                @endforeach
            </select>
        @endif
        <x-input-error :messages="$errors->get('role')" class="mt-2" />
    </div>

    @unless ($editing)
        <div class="sm:col-span-2"
            x-data="{
                password: '',
                generate() {
                    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
                    const bytes = new Uint32Array(14);
                    crypto.getRandomValues(bytes);
                    this.password = Array.from(bytes, (n) => chars[n % chars.length]).join('') + '#7';
                }
            }">
            <x-input-label for="password" value="Mot de passe temporaire" />
            <div class="mt-1 flex gap-2">
                <x-text-input id="password" name="password" type="text" x-model="password"
                    autocomplete="off" class="block w-full font-mono" required />
                <button type="button" @click="generate()"
                    class="rounded-md bg-gray-200 px-3 text-sm text-gray-800 hover:bg-gray-300">Générer</button>
            </div>
            <p class="mt-1 text-xs text-gray-500">
                Au moins 8 caractères. Le titulaire devra le changer à sa première connexion.
            </p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>
    @endunless
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>Enregistrer</x-primary-button>
    <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
</div>