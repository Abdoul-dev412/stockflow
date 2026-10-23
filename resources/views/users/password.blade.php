<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Réinitialiser le mot de passe</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-form-error />

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-700">
                <div class="font-medium text-gray-900">{{ $user->name }}</div>
                <div class="text-gray-500">{{ $user->email }}</div>
                <p class="mt-3">
                    Ses sessions ouvertes seront fermées, et il devra choisir un nouveau mot de passe à sa prochaine connexion.
                </p>
            </div>

            <form method="POST" action="{{ route('users.password.update', $user) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6"
                x-data="{
                    password: '',
                    generate() {
                        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
                        const bytes = new Uint32Array(14);
                        crypto.getRandomValues(bytes);
                        this.password = Array.from(bytes, (n) => chars[n % chars.length]).join('') + '#7';
                    }
                }"
                @submit="confirm('Réinitialiser ce mot de passe ?') || $event.preventDefault()">
                @csrf
                @method('PUT')

                <x-input-label for="password" value="Nouveau mot de passe temporaire" />
                <div class="mt-1 flex gap-2">
                    <x-text-input id="password" name="password" type="text" x-model="password"
                        autocomplete="off" class="block w-full font-mono" required autofocus />
                    <button type="button" @click="generate()"
                        class="rounded-md bg-gray-200 px-3 text-sm text-gray-800 hover:bg-gray-300">Générer</button>
                </div>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />

                <div class="mt-6 flex items-center gap-3">
                    <x-primary-button>Réinitialiser</x-primary-button>
                    <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>