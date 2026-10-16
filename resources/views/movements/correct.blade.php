<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Corriger un mouvement</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-form-error />

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-700 space-y-2">
                <div class="font-medium text-gray-900">
                    {{ $movement->product->name }} <span class="text-gray-500">({{ $movement->product->sku }})</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-movement-badge :type="$movement->type" />
                    <span>{{ $movement->signedQuantity() > 0 ? '+' : '' }}{{ $movement->signedQuantity() }}</span>
                    <span class="text-gray-500">le {{ $movement->occurred_at->format('d/m/Y H:i') }}</span>
                </div>
                <div>Motif : {{ $movement->reason_code->label() }}{{ $movement->reason ? ' — '.$movement->reason : '' }}</div>
            </div>

            <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700">
                La correction annule l'effet de ce mouvement. Stock actuel :
                {{ $movement->product->quantity }} → après correction :
                <strong class="{{ $resultingStock < 0 ? 'text-red-700' : '' }}">{{ $resultingStock }}</strong>
                @if ($resultingStock < 0)
                    <div class="mt-1 text-red-700">Le stock deviendrait négatif : la correction sera refusée.</div>
                @endif
            </div>

            <form method="POST" action="{{ route('movements.correct.store', $movement) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4"
                x-data="{ sending: false }"
                @submit="confirm('Enregistrer cette correction ?') ? sending = true : $event.preventDefault()">
                @csrf
                <div>
                    <x-input-label for="reason" value="Justification de la correction (obligatoire)" />
                    <textarea id="reason" name="reason" rows="3" required autofocus
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                    <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="sending"
                        class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest rounded-md hover:bg-gray-700 disabled:opacity-50">
                        Enregistrer la correction
                    </button>
                    <a href="{{ route('movements.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>