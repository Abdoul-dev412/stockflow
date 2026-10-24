@php
    $config = [
        'type' => $type->value,
        'stock' => $product->quantity,
        'tracked' => (bool) $product->tracks_lots,
        'lots' => $lots,
        'defaultReason' => $reasons[0]->value ?? '',
        'old' => collect(old())->only([
            'quantity', 'counted_quantity', 'reason_code', 'lot_number', 'expires_at', 'lot_id',
        ])->all(),
    ];
@endphp

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
                    @if ($product->tracks_lots)
                        <span class="text-gray-500">· suivi par lots</span>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('movements.store', [$product, $type->value]) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4"
                x-data="movementForm(@js($config))"
                @submit="submit($event)">
                @csrf

                @if ($type->value === 'adjustment')
                    @if ($product->tracks_lots)
                        <div>
                            <x-input-label for="lot_id" value="Lot compté" />
                            <select id="lot_id" name="lot_id" x-model="lotId" required
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">Choisir un lot…</option>
                                @foreach ($lots as $lot)
                                    <option value="{{ $lot['id'] }}">
                                        {{ $lot['number'] }} — {{ $lot['label'] }} — {{ $lot['quantity'] }} en stock
                                    </option>
                                @endforeach
                            </select>
                            @if (count($lots) === 0)
                                <p class="mt-1 text-sm text-amber-700">Aucun lot enregistré pour ce produit.</p>
                            @endif
                            <x-input-error :messages="$errors->get('lot_id')" class="mt-2" />
                        </div>
                    @endif

                    <div>
                        <x-input-label for="counted_quantity"
                            :value="$product->tracks_lots ? 'Quantité constatée dans ce lot' : 'Quantité constatée lors de l\'inventaire'" />
                        <x-text-input id="counted_quantity" name="counted_quantity" type="number" min="0" step="1"
                            class="mt-1 block w-full" x-model="counted" required />
                        <x-input-error :messages="$errors->get('counted_quantity')" class="mt-2" />
                    </div>

                    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700 space-y-1">
                        <div>
                            Stock théorique{{ $product->tracks_lots ? ' du lot' : '' }} :
                            <strong x-text="theoretical ?? '—'"></strong>
                        </div>
                        <div x-show="difference !== null && difference !== 0">
                            Écart :
                            <strong x-text="(difference > 0 ? '+' : '') + difference"></strong>
                        </div>
                        <div class="text-amber-700" x-show="difference === 0">
                            Aucun écart : aucun ajustement ne sera enregistré.
                        </div>
                    </div>
                @else
                    @if ($product->tracks_lots && $type->value === 'entry')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="lot_number" value="Numéro de lot" />
                                <x-text-input id="lot_number" name="lot_number" type="text" list="known-lots"
                                    maxlength="64" class="mt-1 block w-full uppercase"
                                    x-model="lotNumber" @input="syncKnownLot()" required />
                                <datalist id="known-lots">
                                    @foreach ($lots as $lot)
                                        <option value="{{ $lot['number'] }}">{{ $lot['label'] }}</option>
                                    @endforeach
                                </datalist>
                                <p class="mt-1 text-xs text-gray-500" x-show="knownLot">
                                    Ce lot existe déjà : l'entrée le complète.
                                </p>
                                <x-input-error :messages="$errors->get('lot_number')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="expires_at"
                                    :value="$product->is_perishable ? 'Date de péremption' : 'Date de péremption (facultative)'" />
                                <x-text-input id="expires_at" name="expires_at" type="date"
                                    class="mt-1 block w-full" x-model="expires"
                                    ::readonly="knownLot !== null" :required="(bool) $product->is_perishable" />
                                <x-input-error :messages="$errors->get('expires_at')" class="mt-2" />
                            </div>
                        </div>
                    @endif

                    <div>
                        <x-input-label for="quantity" value="Quantité" />
                        <x-text-input id="quantity" name="quantity" type="number" min="1" step="1"
                            class="mt-1 block w-full" x-model="quantity" required autofocus />
                        <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="reason_code" value="Motif" />
                        <select id="reason_code" name="reason_code" required x-model="reason"
                            @change="resetLotIfOutOfPool()"
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

                    @if ($product->tracks_lots && $type->value === 'exit')
                        <div>
                            <x-input-label for="lot_id" value="Lot (facultatif)" />
                            <select id="lot_id" name="lot_id" x-model="lotId"
                                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">Automatique : le lot qui expire en premier</option>
                                @foreach ($lots as $lot)
                                    <option value="{{ $lot['id'] }}" :disabled="lotUnavailable({{ $lot['id'] }})">
                                        {{ $lot['number'] }} — {{ $lot['label'] }} — {{ $lot['quantity'] }} en stock
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500" x-show="removingExpired">
                                Avec le motif « Péremption », seuls les lots expirés peuvent être retirés.
                            </p>
                            <x-input-error :messages="$errors->get('lot_id')" class="mt-2" />
                        </div>
                    @endif

                    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700 space-y-1">
                        <div>
                            Stock actuel : {{ $product->quantity }} → après l'opération :
                            <strong x-text="after"></strong>
                        </div>

                        @if ($product->tracks_lots && $type->value === 'exit')
                            <div x-show="qty > 0 && ! shortage">
                                Prélevé dans :
                                <span x-text="plan.map((line) => line.number + ' (−' + line.take + ')').join(', ')"></span>
                            </div>
                        @endif

                        @if ($type->value === 'exit')
                            <div class="text-red-700" x-show="shortage">
                                Quantité supérieure au stock utilisable
                                (<span x-text="tracked ? available : stock"></span> disponible(s)) :
                                l'opération sera refusée.
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