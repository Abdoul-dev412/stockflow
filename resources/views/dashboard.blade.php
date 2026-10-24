<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tableau de bord</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-flash-status />

            <div class="flex flex-wrap gap-2 text-sm">
                @can('create', App\Models\Product::class)
                    <a href="{{ route('products.create') }}"
                        class="rounded-md bg-gray-800 px-3 py-1.5 text-white hover:bg-gray-700">Nouveau produit</a>
                @endcan
                <a href="{{ route('products.index') }}"
                    class="rounded-md bg-gray-200 px-3 py-1.5 text-gray-800 hover:bg-gray-300">Enregistrer un mouvement</a>
                <a href="{{ route('alerts.index') }}"
                    class="rounded-md bg-gray-200 px-3 py-1.5 text-gray-800 hover:bg-gray-300">Voir les alertes</a>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-stat-card label="Produits actifs" :value="$stats['products']"
                    :href="route('products.index', ['status' => 'active'])" />
                <x-stat-card label="Catégories actives" :value="$stats['categories']"
                    :href="route('categories.index', ['status' => 'active'])" />
                <x-stat-card label="Fournisseurs actifs" :value="$stats['suppliers']"
                    :href="route('suppliers.index', ['status' => 'active'])" />
                <x-stat-card label="Produits en stock faible" :value="$stats['low']" tone="amber"
                    :href="route('alerts.index', ['level' => 'low'])" />
                <x-stat-card label="Produits en rupture" :value="$stats['out']" tone="red"
                    :href="route('alerts.index', ['level' => 'out'])" />
                <x-stat-card :label="'Mouvements ('.$days.' derniers jours)'" :value="$stats['movements']"
                    :href="route('movements.index')" />
                <x-stat-card label="Lots expirés" :value="$stats['expired_lots']" tone="red"
                    :href="route('expiries.index')" />
                <x-stat-card :label="'Lots qui expirent sous '.$expiryDays.' jours'" :value="$stats['expiring_lots']" tone="amber"
                    :href="route('expiries.index')" />
            </div>

            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                <span>Période :</span>
                @foreach ([7, 30, 90] as $option)
                    <a href="{{ route('dashboard', ['days' => $option]) }}"
                        class="rounded-full px-3 py-1 {{ $days === $option ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                        {{ $option }} jours
                    </a>
                @endforeach
                <span class="sm:ml-2">
                    @foreach ($types as $type)
                        {{ $type->label() }} : {{ $byType[$type->value] }}@if (! $loop->last) · @endif
                    @endforeach
                </span>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="font-medium text-gray-900">Produits à réapprovisionner</h3>

                    @forelse ($restock as $product)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 text-sm">
                            <div>
                                <a href="{{ route('products.show', $product) }}"
                                    class="text-indigo-600 hover:underline">{{ $product->name }}</a>
                                <div class="text-xs text-gray-500">{{ $product->sku }} · {{ $product->category->name }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-gray-700">{{ $product->quantity }} / {{ $product->minimum_stock }}</span>
                                <x-stock-badge :status="$product->stockStatus()" />
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Aucun produit à réapprovisionner.</p>
                    @endforelse

                    <a href="{{ route('alerts.index') }}" class="text-sm text-indigo-600 hover:underline">
                        Voir toutes les alertes →
                    </a>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="font-medium text-gray-900">Derniers mouvements</h3>

                    @forelse ($recentMovements as $movement)
                        @php $signed = $movement->signedQuantity(); @endphp
                        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm">
                            <span class="text-gray-500">{{ $movement->occurred_at->format('d/m H:i') }}</span>
                            <x-movement-badge :type="$movement->type" />
                            <span class="font-medium {{ $signed >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                {{ $signed > 0 ? '+' : '' }}{{ $signed }}
                            </span>
                            <span>{{ $movement->product->name }}</span>
                            <span class="text-gray-500">par {{ $movement->user->name }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Aucun mouvement enregistré.</p>
                    @endforelse

                    <a href="{{ route('movements.index') }}" class="text-sm text-indigo-600 hover:underline">
                        Voir tout l'historique →
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>