<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Réapprovisionnement</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <p class="text-sm text-gray-600">
                Quantité suggérée = consommation moyenne × (délai du fournisseur + couverture cible) − stock utilisable.
                Sans historique suffisant, elle vise le double du seuil minimal. Rien n'est envoyé automatiquement :
                tu relis le message, puis tu l'envoies toi-même.
            </p>

            @if ($prepared)
                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-green-500 p-6 space-y-3"
                    x-data="{ copied: false }">
                    <h3 class="font-medium text-gray-900">Message prêt pour {{ $prepared['supplier'] }}</h3>

                    <textarea x-ref="message" readonly rows="10"
                        class="block w-full rounded-md border-gray-300 bg-gray-50 text-sm shadow-sm">{{ $prepared['message'] }}</textarea>

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($prepared['whatsapp'])
                            <a href="{{ $prepared['whatsapp'] }}" target="_blank" rel="noopener noreferrer"
                                class="rounded-md bg-green-600 px-3 py-1.5 text-white hover:bg-green-700">Ouvrir WhatsApp</a>
                        @else
                            <span class="text-gray-500">WhatsApp indisponible : téléphone absent ou invalide.</span>
                        @endif

                        @if ($prepared['mailto'])
                            <a href="{{ $prepared['mailto'] }}"
                                class="rounded-md bg-gray-200 px-3 py-1.5 text-gray-800 hover:bg-gray-300">Ouvrir l'email</a>
                        @endif

                        <button type="button"
                            @click="navigator.clipboard.writeText($refs.message.value).then(() => copied = true)"
                            class="rounded-md bg-gray-200 px-3 py-1.5 text-gray-800 hover:bg-gray-300">Copier le message</button>
                        <span x-show="copied" class="text-green-700">Copié.</span>
                    </div>
                </div>
            @endif

            @forelse ($groups as $group)
                @php
                    $supplier = $group['supplier'];
                    $canPrepare = $supplier !== null && $supplier->is_active;
                @endphp

                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <div>
                        <h3 class="font-medium text-gray-900">{{ $supplier?->name ?? 'Sans fournisseur' }}</h3>
                        @if ($supplier)
                            <p class="text-xs text-gray-500">
                                Délai de livraison : {{ $supplier->lead_time_days }} j
                                @unless ($supplier->is_active)
                                    · <span class="text-red-700">fournisseur désactivé</span>
                                @endunless
                            </p>
                        @else
                            <p class="text-xs text-gray-500">
                                Associe un fournisseur à ces produits pour pouvoir préparer un message.
                            </p>
                        @endif
                    </div>

                    @if ($canPrepare)
                        <form method="POST" action="{{ route('replenishment.prepare', $supplier) }}" class="space-y-3">
                            @csrf
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    @if ($canPrepare)
                                        <th class="px-3 py-2"></th>
                                    @endif
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Produit</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Stock utilisable</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Consommation</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Couverture</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600">Suggestion</th>
                                    @if ($canPrepare)
                                        <th class="px-3 py-2 text-left font-medium text-gray-600">À commander</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($group['items'] as $suggestion)
                                    @php
                                        $product = $suggestion->product;
                                        $coverage = $suggestion->coverage;
                                    @endphp
                                    <tr>
                                        @if ($canPrepare)
                                            <td class="px-3 py-2">
                                                <input type="hidden" name="items[{{ $product->id }}][include]" value="0">
                                                <input type="checkbox" name="items[{{ $product->id }}][include]" value="1"
                                                    @checked(old("items.{$product->id}.include", '1') == '1')
                                                    class="rounded border-gray-300 text-indigo-600 shadow-sm">
                                            </td>
                                        @endif
                                        <td class="px-3 py-2">
                                            <a href="{{ route('products.show', $product) }}"
                                                class="text-indigo-600 hover:underline">{{ $product->name }}</a>
                                            <div class="text-xs text-gray-500">{{ $product->sku }}</div>
                                        </td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $coverage->usableQuantity }} {{ $product->unit }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            @if ($coverage->isDetermined())
                                                {{ number_format($coverage->dailyConsumption, 2, ',', ' ') }} / jour
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-2"><x-coverage-badge :coverage="$coverage" /></td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            {{ $suggestion->quantity }} {{ $product->unit }}
                                            <div class="text-xs text-gray-500">{{ $suggestion->basisLabel() }}</div>
                                        </td>
                                        @if ($canPrepare)
                                            <td class="px-3 py-2">
                                                <input type="number" min="1" step="1"
                                                    name="items[{{ $product->id }}][quantity]"
                                                    value="{{ old("items.{$product->id}.quantity", $suggestion->quantity) }}"
                                                    class="w-28 rounded-md border-gray-300 text-sm shadow-sm">
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($canPrepare)
                            <x-primary-button>Préparer le message</x-primary-button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-500">
                    Aucun produit à réapprovisionner pour le moment.
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>