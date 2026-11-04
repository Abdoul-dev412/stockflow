@php
    use App\Support\Money;

    $classStyles = [
        'A' => 'bg-green-100 text-green-800',
        'B' => 'bg-amber-100 text-amber-800',
        'C' => 'bg-gray-100 text-gray-700',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analyse du stock</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('analysis._tabs')

            <p class="text-sm text-gray-600">
                Valeur de consommation = ventes et consommation interne des {{ $windowDays }} derniers jours × prix d'achat actuel.
                Classe A jusqu'à {{ config('stockflow.abc.a_percent') }} % de la valeur cumulée,
                B jusqu'à {{ config('stockflow.abc.b_percent') }} %, C ensuite. Les pertes ne sont pas de la consommation.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($summary as $class => $row)
                    <div class="bg-white shadow-sm sm:rounded-lg p-5">
                        <span class="rounded-full px-2 py-1 text-xs {{ $classStyles[$class] }}">Classe {{ $class }}</span>
                        <div class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ number_format($row['share'], 1, ',', ' ') }} %
                        </div>
                        <div class="text-sm text-gray-600">
                            {{ $row['products'] }} produit(s) · {{ Money::format($row['value']) }}
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="px-4 pt-4 font-medium text-gray-900">Classement des produits</div>
                <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Classe</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Unités consommées</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Valeur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Part</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Cumul</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($classified as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-xs {{ $classStyles[$row['class']] }}">{{ $row['class'] }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $row['product']) }}"
                                        class="text-indigo-600 hover:underline">{{ $row['product']->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $row['product']->sku }}</div>
                                </td>
                                <td class="px-4 py-3">{{ number_format($row['units'], 0, ',', ' ') }} {{ $row['product']->unit }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ Money::format($row['value']) }}</td>
                                <td class="px-4 py-3">{{ number_format($row['share'], 1, ',', ' ') }} %</td>
                                <td class="px-4 py-3">{{ number_format($row['cumulative'], 1, ',', ' ') }} %</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                                    Aucune consommation sur la période : rien à classer.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $classified->withQueryString()->links() }}

            <p class="text-sm text-gray-600">
                {{ $unclassified }} produit(s) actif(s) non classé(s) : aucune consommation sur la période.
            </p>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                    <h3 class="font-medium text-gray-900">Produits dormants</h3>
                    <span class="text-sm text-gray-600">
                        Capital immobilisé : <strong class="text-gray-900">{{ Money::format($capital) }}</strong>
                    </span>
                </div>
                <p class="px-4 text-xs text-gray-500">
                    En stock, sans vente ni consommation interne depuis {{ $threshold }} jours
                    (à défaut, depuis le premier mouvement).
                </p>

                <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Catégorie</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Stock</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Dernière activité</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Inactif depuis</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Capital immobilisé</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($dormant as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $row['product']) }}"
                                        class="text-indigo-600 hover:underline">{{ $row['product']->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $row['product']->sku }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $row['product']->category->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $row['product']->quantity }} {{ $row['product']->unit }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $row['reference_at']->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $row['idle_days'] }} j</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ Money::format($row['capital']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-gray-500">Aucun produit dormant.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $dormant->withQueryString()->links() }}
        </div>
    </div>
</x-app-layout>