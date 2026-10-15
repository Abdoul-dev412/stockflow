<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $product->name }}</h2>
            @can('update', $product)
                <a href="{{ route('products.edit', $product) }}"
                    class="text-sm text-gray-700 hover:underline">Modifier</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <div class="text-sm text-gray-500">Stock disponible</div>
                        <div class="text-3xl font-semibold text-gray-900">
                            {{ $product->quantity }} <span class="text-base font-normal">{{ $product->unit }}</span>
                        </div>
                    </div>
                    <x-stock-badge :status="$product->stockStatus()" />
                    @unless ($product->is_active)
                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Produit inactif</span>
                    @endunless
                </div>
                <p class="mt-2 text-sm text-gray-500">Seuil minimal : {{ $product->minimum_stock }}</p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 sm:grid-cols-2 text-sm text-gray-700">
                <div><span class="text-gray-500">SKU :</span> {{ $product->sku }}</div>
                <div><span class="text-gray-500">Code-barres :</span> {{ $product->barcode ?: '—' }}</div>
                <div>
                    <span class="text-gray-500">Catégorie :</span>
                    <a href="{{ route('categories.show', $product->category) }}"
                        class="text-indigo-600 hover:underline">{{ $product->category->name }}</a>
                </div>
                <div>
                    <span class="text-gray-500">Fournisseur :</span>
                    @if ($product->supplier)
                        <a href="{{ route('suppliers.show', $product->supplier) }}"
                            class="text-indigo-600 hover:underline">{{ $product->supplier->name }}</a>
                    @else
                        —
                    @endif
                </div>
                <div><span class="text-gray-500">Prix d'achat :</span> {{ number_format($product->purchase_price, 2, ',', ' ') }}</div>
                <div>
                    <span class="text-gray-500">Prix de vente :</span>
                    {{ $product->sale_price !== null ? number_format($product->sale_price, 2, ',', ' ') : '—' }}
                </div>
                <div class="sm:col-span-2"><span class="text-gray-500">Description :</span> {{ $product->description ?: '—' }}</div>
                <div class="text-gray-500">Créé le {{ $product->created_at->format('d/m/Y') }}, modifié le {{ $product->updated_at->format('d/m/Y') }}</div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-500">
                L'historique des mouvements de ce produit apparaîtra ici dès le jour 7.
            </div>

            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline">
                ← Retour aux produits
            </a>
        </div>
    </div>
</x-app-layout>