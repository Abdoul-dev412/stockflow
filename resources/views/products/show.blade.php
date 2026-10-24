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
            <x-form-error />

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

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                
        @if ($product->tracks_lots)
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-medium text-gray-900">
                    Lots en stock
                    @if ($product->is_perishable)
                        <span class="text-sm font-normal text-gray-500">· périssable</span>
                    @endif
                </h3>

                @can('create', App\Models\StockMovement::class)
                    @if ($product->is_active && $lots->contains(fn ($lot) => $lot->isExpired()))
                        <form method="POST" action="{{ route('expiries.remove', $product) }}"
                            onsubmit="return confirm('Retirer du stock tous les lots expirés de ce produit ?')">
                            @csrf
                            <button class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">
                                Retirer les lots expirés
                            </button>
                        </form>
                    @endif
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Lot</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Péremption</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Quantité</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-600">Reçu le</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($lots as $lot)
                            <tr>
                                <td class="px-4 py-2">{{ $lot->lot_number }}</td>
                                <td class="px-4 py-2">
                                    {{ $lot->expires_at?->format('d/m/Y') ?? '—' }}
                                    <x-lot-expiry-badge :lot="$lot" />
                                </td>
                                <td class="px-4 py-2">{{ $lot->quantity_remaining }} {{ $product->unit }}</td>
                                <td class="px-4 py-2">{{ $lot->received_at->format('d/m/Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-4 text-center text-gray-500">Aucun lot en stock.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    

    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-medium text-gray-900">Derniers mouvements</h3>

        @can('create', App\Models\StockMovement::class)
            @if ($product->is_active)
                <div class="flex gap-2 text-sm">
                    <a href="{{ route('movements.create', [$product, 'entry']) }}"
                        class="rounded-md bg-green-600 px-3 py-1.5 text-white hover:bg-green-700">Entrée</a>
                    <a href="{{ route('movements.create', [$product, 'exit']) }}"
                        class="rounded-md bg-orange-600 px-3 py-1.5 text-white hover:bg-orange-700">Sortie</a>
                    <a href="{{ route('movements.create', [$product, 'adjustment']) }}"
                        class="rounded-md bg-blue-600 px-3 py-1.5 text-white hover:bg-blue-700">Ajustement</a>
                </div>
            @endif
        @endcan
    </div>

    @forelse ($movements as $movement)
        @php $signed = $movement->signedQuantity(); @endphp
        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm">
            <span class="text-gray-500">{{ $movement->occurred_at->format('d/m/Y H:i') }}</span>
            <x-movement-badge :type="$movement->type" />
            <span class="font-medium {{ $signed >= 0 ? 'text-green-700' : 'text-red-700' }}">
                {{ $signed > 0 ? '+' : '' }}{{ $signed }}
            </span>
            <span class="text-gray-500">{{ $movement->quantity_before }} → {{ $movement->quantity_after }}</span>
            <span>{{ $movement->reason_code->label() }}</span>
            <span class="text-gray-500">par {{ $movement->user->name }}</span>
        </div>
            @empty
                <p class="text-sm text-gray-500">Aucun mouvement enregistré pour ce produit.</p>
            @endforelse

            <a href="{{ route('movements.index', ['product' => $product->id]) }}"
                class="text-sm text-indigo-600 hover:underline">Voir tout l'historique →</a>
        </div>

            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline">
                ← Retour aux produits
            </a>
        </div>
    </div>
</x-app-layout>