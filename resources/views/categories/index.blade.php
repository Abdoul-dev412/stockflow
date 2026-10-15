<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Catégories</h2>
            @can('create', App\Models\Category::class)
                <a href="{{ route('categories.create') }}"
                    class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700">
                    Nouvelle catégorie
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Rechercher par nom"
                    class="border-gray-300 rounded-md shadow-sm text-sm">
                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les statuts</option>
                    <option value="active" @selected($status === 'active')>Actives</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactives</option>
                </select>
                <x-secondary-button type="submit">Filtrer</x-secondary-button>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Nom</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produits</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Statut</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($categories as $category)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('categories.show', $category) }}"
                                        class="text-indigo-600 hover:underline">{{ $category->name }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $category->products_count }}</td>
                                <td class="px-4 py-3">
                                    @if ($category->is_active)
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">Active</span>
                                    @else
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @can('update', $category)
                                        <a href="{{ route('categories.edit', $category) }}"
                                            class="text-sm text-gray-700 hover:underline">Modifier</a>
                                        <form method="POST" action="{{ route('categories.toggle', $category) }}"
                                            class="inline"
                                            onsubmit="return confirm('{{ $category->is_active ? 'Désactiver' : 'Réactiver' }} cette catégorie ?')">
                                            @csrf
                                            @method('PATCH')
                                            <button class="ml-3 text-sm text-red-600 hover:underline">
                                                {{ $category->is_active ? 'Désactiver' : 'Réactiver' }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                                    Aucune catégorie trouvée.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $categories->links() }}
        </div>
    </div>
</x-app-layout>