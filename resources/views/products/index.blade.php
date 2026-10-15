<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Produits</h2>
            @can('create', App\Models\Product::class)
                <a href="{{ route('products.create') }}"
                    class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700">
                    Nouveau produit
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Nom, SKU ou code-barres"
                    class="border-gray-300 rounded-md shadow-sm text-sm">

                <select name="category" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Toutes les catégories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($categoryId === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les statuts</option>
                    <option value="active" @selected($status === 'active')>Actifs</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactifs</option>
                </select>

                <select name="stock" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les niveaux de stock</option>
                    <option value="normal" @selected($stock === 'normal')>Stock normal</option>
                    <option value="low" @selected($stock === 'low')>Stock faible</option>
                    <option value="out" @selected($stock === 'out')>Rupture</option>
                </select>

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('products.index') }}"
                    class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <x-sortable-th column="sku" label="SKU" :sort="$sort" :direction="$direction" />
                            <x-sortable-th column="name" label="Produit" :sort="$sort" :direction="$direction" />
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Catégorie</th>
                            <x-sortable-th column="quantity" label="Quantité" :sort="$sort" :direction="$direction" />
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Niveau</th>
                            <x-sortable-th column="purchase_price" label="Prix d'achat" :sort="$sort" :direction="$direction" />
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Statut</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $product->sku }}</td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $product) }}"
                                        class="text-indigo-600 hover:underline">{{ $product->name }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $product->category->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $product->quantity }} {{ $product->unit }}</td>
                                <td class="px-4 py-3"><x-stock-badge :status="$product->stockStatus()" /></td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ number_format($product->purchase_price, 2, ',', ' ') }}</td>
                                <td class="px-4 py-3">
                                    @if ($product->is_active)
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs text-green-800">Actif</span>
                                    @else
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Inactif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @can('update', $product)
                                        <a href="{{ route('products.edit', $product) }}"
                                            class="text-sm text-gray-700 hover:underline">Modifier</a>
                                        <form method="POST" action="{{ route('products.toggle', $product) }}"
                                            class="inline"
                                            onsubmit="return confirm('{{ $product->is_active ? 'Désactiver' : 'Réactiver' }} ce produit ?')">
                                            @csrf
                                            @method('PATCH')
                                            <button class="ml-3 text-sm text-red-600 hover:underline">
                                                {{ $product->is_active ? 'Désactiver' : 'Réactiver' }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                                    Aucun produit trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>