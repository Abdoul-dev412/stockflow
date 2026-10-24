<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Péremptions</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat-card label="Lots expirés" :value="$counts['expired']" tone="red" />
                <x-stat-card label="Unités expirées à retirer" :value="$counts['expired_units']" tone="red" />
                <x-stat-card :label="'Lots qui expirent sous '.$days.' jours'" :value="$counts['soon']" tone="amber" />
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
                <span>Horizon :</span>
                @foreach ([7, 15, 30, 60, 90] as $option)
                    <a href="{{ route('expiries.index', ['days' => $option]) }}"
                        class="rounded-full px-3 py-1 {{ $days === $option ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                        {{ $option }} jours
                    </a>
                @endforeach
            </div>

            @php $removalShown = []; @endphp

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Lot</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Péremption</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Quantité</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($lots as $lot)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $lot->product) }}"
                                        class="text-indigo-600 hover:underline">{{ $lot->product->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $lot->product->sku }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $lot->lot_number }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ $lot->expires_at->format('d/m/Y') }}
                                    <x-lot-expiry-badge :lot="$lot" />
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $lot->quantity_remaining }} {{ $lot->product->unit }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($lot->isExpired() && ! isset($removalShown[$lot->product_id]))
                                        @php $removalShown[$lot->product_id] = true; @endphp
                                        @can('create', App\Models\StockMovement::class)
                                            <form method="POST" action="{{ route('expiries.remove', $lot->product) }}"
                                                class="inline"
                                                onsubmit="return confirm('Retirer du stock tous les lots expirés de ce produit ?')">
                                                @csrf
                                                <button class="text-sm text-red-600 hover:underline">
                                                    Retirer les lots expirés
                                                </button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                    Aucun lot expiré ou proche de sa péremption sur cet horizon.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $lots->links() }}
        </div>
    </div>
</x-app-layout>