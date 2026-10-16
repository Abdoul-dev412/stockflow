<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Historique des mouvements
            @if ($productFilter)
                <span class="text-gray-500">— {{ $productFilter->name }}</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-2">
                @if ($productFilter)
                    <input type="hidden" name="product" value="{{ $productFilter->id }}">
                @endif

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Produit : nom ou SKU"
                    class="border-gray-300 rounded-md shadow-sm text-sm">

                <select name="type" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(request('type') === $type->value)>
                            {{ $type->label() }}
                        </option>
                    @endforeach
                </select>

                <select name="user" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Tous les utilisateurs</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((int) request('user') === $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>

                <label class="text-sm text-gray-600">Du
                    <input type="date" name="from" value="{{ request('from') }}"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                </label>
                <label class="text-sm text-gray-600">Au
                    <input type="date" name="to" value="{{ request('to') }}"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                </label>

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('movements.index') }}"
                    class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Type</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Variation</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Stock</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Motif</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Utilisateur</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($movements as $movement)
                            @php $signed = $movement->signedQuantity(); @endphp
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $movement->occurred_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('products.show', $movement->product) }}"
                                        class="text-indigo-600 hover:underline">{{ $movement->product->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $movement->product->sku }}</div>
                                </td>
                                <td class="px-4 py-3"><x-movement-badge :type="$movement->type" /></td>
                                <td class="px-4 py-3 whitespace-nowrap font-medium {{ $signed >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                    {{ $signed > 0 ? '+' : '' }}{{ $signed }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $movement->quantity_before }} → {{ $movement->quantity_after }}</td>
                                <td class="px-4 py-3">
                                    {{ $movement->reason_code->label() }}
                                    @if ($movement->reason)
                                        <div class="text-xs text-gray-500">{{ $movement->reason }}</div>
                                    @endif
                                    @if ($movement->corrects_movement_id)
                                        <div class="text-xs text-purple-700">Corrige le mouvement n° {{ $movement->corrects_movement_id }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $movement->user->name }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if ($movement->correction_exists)
                                        <span class="rounded-full bg-gray-200 px-2 py-1 text-xs text-gray-700">Corrigé</span>
                                    @elseif ($movement->type !== \App\Enums\MovementType::Correction
                                        && $movement->status === \App\Enums\MovementStatus::Applied)
                                        @can('correct', $movement)
                                            <a href="{{ route('movements.correct.create', $movement) }}"
                                                class="text-sm text-red-600 hover:underline">Corriger</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                                    Aucun mouvement trouvé.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $movements->links() }}
        </div>
    </div>
</x-app-layout>