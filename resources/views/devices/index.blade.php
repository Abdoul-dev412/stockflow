<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Appareils</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            @if ($issued = session('device_token'))
                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-green-500 p-6 space-y-3"
                    x-data="{ copied: false }">
                    <h3 class="font-medium text-gray-900">Jeton à copier maintenant : {{ $issued['name'] }}</h3>
                    <p class="text-sm text-gray-600">
                        Ce jeton n'est affiché qu'une seule fois et n'est conservé nulle part sous cette forme.
                        Il est valable jusqu'au {{ $issued['expires_at'] }}.
                    </p>
                    <textarea x-ref="token" readonly rows="2"
                        class="block w-full rounded-md border-gray-300 bg-gray-50 font-mono text-sm shadow-sm">{{ $issued['token'] }}</textarea>
                    <button type="button"
                        @click="navigator.clipboard.writeText($refs.token.value).then(() => copied = true)"
                        class="rounded-md bg-gray-200 px-3 py-1.5 text-sm text-gray-800 hover:bg-gray-300">Copier</button>
                    <span x-show="copied" class="text-sm text-green-700">Copié.</span>
                </div>
            @endif

            <form method="POST" action="{{ route('devices.store') }}"
                class="bg-white shadow-sm sm:rounded-lg p-6 flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-64 flex-1">
                    <x-input-label for="name" value="Enregistrer un nouvel appareil" />
                    <x-text-input id="name" name="name" type="text" maxlength="100" class="mt-1 block w-full"
                        placeholder="Ex. : Tablette du magasin" :value="old('name')" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <x-primary-button>Enregistrer</x-primary-button>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Appareil</th>
                            @if ($isAdmin)
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Propriétaire</th>
                            @endif
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Dernière activité</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">État</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($devices as $device)
                            <tr>
                                <td class="px-4 py-3">{{ $device->name }}</td>
                                @if ($isAdmin)
                                    <td class="px-4 py-3">{{ $device->user->name }}</td>
                                @endif
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $device->last_seen_at?->format('d/m/Y H:i') ?? 'Jamais' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if ($device->isRevoked())
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs text-red-800">Révoqué</span>
                                    @elseif ($device->tokenExpired())
                                        <span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800">Jeton expiré</span>
                                    @else
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">Actif</span>
                                        <span class="text-xs text-gray-500">jusqu'au {{ $device->token_expires_at->format('d/m/Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @can('renew', $device)
                                        <form method="POST" action="{{ route('devices.renew', $device) }}" class="inline"
                                            onsubmit="return confirm('Générer un nouveau jeton ? L\'ancien cessera de fonctionner.')">
                                            @csrf
                                            <button class="text-sm text-gray-700 hover:underline">Renouveler le jeton</button>
                                        </form>
                                    @endcan
                                    @can('revoke', $device)
                                        <form method="POST" action="{{ route('devices.revoke', $device) }}" class="ml-3 inline"
                                            onsubmit="return confirm('Révoquer cet appareil ? Ses opérations futures seront rejetées.')">
                                            @csrf
                                            @method('PATCH')
                                            <button class="text-sm text-red-600 hover:underline">Révoquer</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isAdmin ? 5 : 4 }}" class="px-4 py-6 text-center text-gray-500">
                                    Aucun appareil enregistré.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>