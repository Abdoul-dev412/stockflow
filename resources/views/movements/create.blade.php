<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $type->label() }} de stock
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-form-error />

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-700">
                <div class="font-medium text-gray-900">
                    {{ $product->name }} <span class="text-gray-500">({{ $product->sku }})</span>
                </div>
                <div class="mt-1">
                    Stock actuel : <strong>{{ $product->quantity }} {{ $product->unit }}</strong>
                </div>
            </div>

            <form method="POST" action="{{ route('movements.store', [$product, $type->value]) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4"
                x-data="{ sending: false, quantity: @js(old('quantity', '')), counted: @js(old('counted_quantity', '')) }"
                @submit="confirm('Valider cette opération ?') ? sending = true : $event.preventDefault()">
                @csrf

                @if ($type->value === 'adjustment')
                    <div>
                        <x-input-label for="counted_quantity" value="Quantité constatée lors de l'inventaire" />
                        <x-text-input id="counted_quantity" name="counted_quantity" type="number" min="0" step="1"
                            class="mt-1 block w-full" x-model.number="counted"
                            :value="old('counted_quantity')" required autofocus />
                        <x-input-error :messages="$errors->get('counted_quantity')" class="mt-2" />
                    </div>

                    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700 space-y-1">
                        <div>Stock théorique : {{ $product->quantity }}</div>
                        <div x-show="counted !== '' && Number(counted) !== {{ $product->quantity }}">
                            Écart :
                            <strong x-text="(Number(counted) - {{ $product->quantity }} > 0 ? '+' : '') + (Number(counted) - {{ $product->quantity }})"></strong>
                        </div>
                        <div class="text-amber-700" x-show="counted !== '' && Number(counted) === {{ $product->quantity }}">
                            Aucun écart : aucun ajustement ne sera enregistré.
                        </div>
                    </div>
                @else
                    <div>
                        <x-input-label for="quantity" value="Quantité" />
                        <x-text-input id="quantity" name="quantity" type="number" min="1" step="1"
                            class="mt-1 block w-full" x-model.number="quantity"
                            :value="old('quantity')" required autofocus />
                        <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="reason_code" value="Motif" />
                        <select id="reason_code" name="reason_code" required
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->value }}"
                                    @selected(old('reason_code', $reasons[0]->value) === $reason->value)>
                                    {{ $reason->label() }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('reason_code')" class="mt-2" />
                    </div>

                    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700 space-y-1">
                        <div>
                            Stock actuel : {{ $product->quantity }} → après l'opération :
                            <strong x-text="{{ $product->quantity }} {{ $type->value === 'entry' ? '+' : '-' }} (Number(quantity) || 0)"></strong>
                        </div>
                        @if ($type->value === 'exit')
                            <div class="text-red-700" x-show="(Number(quantity) || 0) > {{ $product->quantity }}">
                                Quantité supérieure au stock disponible : l'opération sera refusée.
                            </div>
                        @endif
                    </div>
                @endif

                <div>
                    <x-input-label for="reason"
                        :value="$type->value === 'adjustment' ? 'Justification (obligatoire)' : 'Commentaire (obligatoire pour le motif « Autre »)'" />
                    <textarea id="reason" name="reason" rows="3"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="sending"
                        class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700 disabled:opacity-50">
                        Valider
                    </button>
                    <a href="{{ route('products.show', $product) }}"
                        class="text-sm text-gray-600 hover:underline">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>