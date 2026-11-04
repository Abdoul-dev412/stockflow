@php use App\Support\Money; @endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analyse du stock</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('analysis._tabs')

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <p class="text-sm text-gray-600">
                Valorisation au prix d'achat actuel de chaque produit (l'historique des prix n'est pas conservé).
                Les produits désactivés sont inclus : leur stock existe toujours.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat-card label="Valeur du stock" :value="Money::format($summary['value'])" />
                <x-stat-card label="Unités en stock" :value="number_format($summary['units'], 0, ',', ' ')" />
                <x-stat-card label="Produits en stock" :value="$summary['in_stock'].' sur '.$summary['products']" />
                <x-stat-card label="Dont lots expirés à retirer" :value="Money::format($summary['expired_value'])" tone="red"
                    :href="route('expiries.index')" />
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="px-4 pt-4 font-medium text-gray-900">Valeur par catégorie</div>
                <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Catégorie</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produits en stock</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Unités</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Valeur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Part</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($categories as $category)
                            @php $share = $totalCents > 0 ? round($category['cents'] / $totalCents * 100, 1) : 0; @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('categories.show', $category['id']) }}"
                                        class="text-indigo-600 hover:underline">{{ $category['name'] }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $category['in_stock'] }} / {{ $category['products'] }}</td>
                                <td class="px-4 py-3">{{ number_format($category['units'], 0, ',', ' ') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ Money::format($category['value']) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    {{ number_format($share, 1, ',', ' ') }} %
                                    <div class="mt-1 h-2 w-32 rounded bg-gray-100">
                                        <div class="h-2 rounded bg-indigo-400" style="width: {{ (int) round($share) }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Aucun produit.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-medium text-gray-900">Coût des pertes</h3>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @foreach ($periods as $option)
                            <a href="{{ route('analysis.valuation', ['days' => $option]) }}"
                                class="rounded-full px-3 py-1 {{ $days === $option ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                                {{ $option }} jours
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach ($losses['rows'] as $row)
                        <div>
                            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                <span class="font-medium text-gray-900">{{ $row['label'] }}</span>
                                <span class="text-gray-600">
                                    {{ $row['movements'] }} mouvement(s) · {{ $row['units'] }} unité(s) ·
                                    <strong class="text-gray-900">{{ Money::format($row['value']) }}</strong>
                                </span>
                            </div>
                            <div class="mt-1 h-3 w-full rounded bg-gray-100">
                                <div class="h-3 rounded bg-red-400" style="width: {{ $row['width'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="border-t border-gray-100 pt-3 text-sm text-gray-700">
                    Total sur {{ $days }} jours : <strong>{{ Money::format($losses['total_value']) }}</strong>
                    <span class="text-xs text-gray-500">(mouvements annulés par une correction exclus)</span>
                </p>
            </div>
        </div>
    </div>
</x-app-layout>