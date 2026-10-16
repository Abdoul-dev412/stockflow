<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Alertes de stock</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-stat-card label="Produits en stock faible" :value="$counts['low']" tone="amber"
                    :href="route('alerts.index', ['level' => 'low'])" />
                <x-stat-card label="Produits en rupture" :value="$counts['out']" tone="red"
                    :href="route('alerts.index', ['level' => 'out'])" />
            </div>

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <form method="GET" class="flex flex-wrap gap-2">
                <select name="level" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Faible et rupture</option>
                    <option value="low" @selected($level === 'low')>Stock faible</option>
                    <option value="out" @selected($level === 'out')>Rupture</option>
                </select>

                <select name="category" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Toutes les catégories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) $categoryId === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                <select name="supplier" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les fournisseurs</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) $supplierId === $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('alerts.index') }}"
                    class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Catégorie</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Fournisseur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Stock</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Seuil</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Manque</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Niveau</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $product) }}"
                                        class="text-indigo-600 hover:underline">{{ $product->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $product->sku }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $product->category->name }}</td>
                                <td class="px-4 py-3">
                                    {{ $product->supplier?->name ?? '—' }}
                                    @if ($product->supplier?->phone)
                                        <div class="text-xs text-gray-500">{{ $product->supplier->phone }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $product->quantity }} {{ $product->unit }}</td>
                                <td class="px-4 py-3">{{ $product->minimum_stock }}</td>
                                <td class="px-4 py-3">{{ $product->minimum_stock - $product->quantity }}</td>
                                <td class="px-4 py-3"><x-stock-badge :status="$product->stockStatus()" /></td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @can('create', App\Models\StockMovement::class)
                                        <a href="{{ route('movements.create', [$product, 'entry']) }}"
                                            class="text-sm text-green-700 hover:underline">Enregistrer une entrée</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                                    Aucune alerte : tous les stocks sont au-dessus de leur seuil.
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