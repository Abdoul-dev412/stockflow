<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Utilisateurs</h2>
            <a href="{{ route('users.create') }}"
                class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700">
                Nouveau compte
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Nom ou email"
                    class="border-gray-300 rounded-md shadow-sm text-sm">

                <select name="role" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les rôles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(request('role') === $role->value)>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les statuts</option>
                    <option value="active" @selected(request('status') === 'active')>Actifs</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Désactivés</option>
                </select>

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('users.index') }}"
                    class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Nom</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Rôle</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Statut</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($users as $user)
                            @php $isSelf = $user->is(auth()->user()); @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    {{ $user->name }}
                                    @if ($isSelf)
                                        <span class="text-xs text-gray-500">(toi)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $user->email }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs {{ $user->role->badgeClasses() }}">
                                        {{ $user->role->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($user->is_active)
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">Actif</span>
                                    @else
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Désactivé</span>
                                    @endif
                                    @if ($user->must_change_password)
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800">Mot de passe temporaire</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('users.edit', $user) }}"
                                        class="text-sm text-gray-700 hover:underline">Modifier</a>

                                    @unless ($isSelf)
                                        <a href="{{ route('users.password.edit', $user) }}"
                                            class="ml-3 text-sm text-gray-700 hover:underline">Mot de passe</a>
                                        <form method="POST" action="{{ route('users.toggle', $user) }}" class="inline"
                                            onsubmit="return confirm('{{ $user->is_active ? 'Désactiver' : 'Réactiver' }} ce compte ?')">
                                            @csrf
                                            @method('PATCH')
                                            <button class="ml-3 text-sm text-red-600 hover:underline">
                                                {{ $user->is_active ? 'Désactiver' : 'Réactiver' }}
                                            </button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                    Aucun compte trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>