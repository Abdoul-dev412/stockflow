<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Fournisseurs</h2>
            @can('create', App\Models\Supplier::class)
                <a href="{{ route('suppliers.create') }}"
                    class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700">
                    Nouveau fournisseur
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Nom, contact ou email"
                    class="border-gray-300 rounded-md shadow-sm text-sm">
                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les statuts</option>
                    <option value="active" @selected($status === 'active')>Actifs</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactifs</option>
                </select>
                <x-secondary-button type="submit">Filtrer</x-secondary-button>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Fournisseur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Contact</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Délai</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produits</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Statut</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($suppliers as $supplier)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('suppliers.show', $supplier) }}"
                                        class="text-indigo-600 hover:underline">{{ $supplier->name }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    {{ $supplier->contact_name ?: '—' }}
                                    @if ($supplier->phone)
                                        <div class="text-xs text-gray-500">{{ $supplier->phone }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $supplier->lead_time_days }} j</td>
                                <td class="px-4 py-3">{{ $supplier->products_count }}</td>
                                <td class="px-4 py-3">
                                    @if ($supplier->is_active)
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">Actif</span>
                                    @else
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Inactif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @can('update', $supplier)
                                        <a href="{{ route('suppliers.edit', $supplier) }}"
                                            class="text-sm text-gray-700 hover:underline">Modifier</a>
                                        <form method="POST" action="{{ route('suppliers.toggle', $supplier) }}"
                                            class="inline"
                                            onsubmit="return confirm('{{ $supplier->is_active ? 'Désactiver' : 'Réactiver' }} ce fournisseur ?')">
                                            @csrf
                                            @method('PATCH')
                                            <button class="ml-3 text-sm text-red-600 hover:underline">
                                                {{ $supplier->is_active ? 'Désactiver' : 'Réactiver' }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                                    Aucun fournisseur trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $suppliers->links() }}
        </div>
    </div>
</x-app-layout>