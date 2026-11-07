@php
    use App\Enums\MovementStatus;

    $badges = [
        'conflict' => 'bg-red-100 text-red-800',
        'pending_validation' => 'bg-amber-100 text-amber-800',
        'applied' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-gray-200 text-gray-700',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Synchronisation</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash-status />
            <x-form-error />

            @if ($errors->any() && ! $errors->has('form'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-stat-card label="Conflits à résoudre" :value="$conflictCount" tone="red"
                    :href="route('sync-operations.index', ['status' => 'conflict'])" />
                <x-stat-card label="Inventaires à valider" :value="$pendingCount" tone="amber"
                    :href="route('sync-operations.index', ['status' => 'pending_validation'])" />
            </div>

            <form method="GET" class="flex flex-wrap gap-2">
                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="open" @selected($status === 'open')>À traiter</option>
                    <option value="conflict" @selected($status === 'conflict')>Conflits</option>
                    <option value="pending_validation" @selected($status === 'pending_validation')>Inventaires en attente</option>
                    <option value="applied" @selected($status === 'applied')>Appliquées</option>
                    <option value="rejected" @selected($status === 'rejected')>Rejetées</option>
                    <option value="all" @selected($status === 'all')>Toutes</option>
                </select>

                @if ($devices->isNotEmpty())
                    <select name="device" class="border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">Tous les appareils</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" @selected((int) request('device') === $device->id)>{{ $device->name }}</option>
                        @endforeach
                    </select>
                @endif

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('sync-operations.index') }}" class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="space-y-3">
                @forelse ($operations as $operation)
                    @php
                        $payload = $operation->payload;
                        $isConflict = $operation->status === MovementStatus::Conflict;
                        $isPending = $operation->status === MovementStatus::PendingValidation;
                        $lot = isset($payload['lot_id']) ? $lots->get((int) $payload['lot_id']) : null;
                    @endphp

                    <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-1 text-xs {{ $badges[$operation->status->value] }}">
                                {{ $operation->status->label() }}
                            </span>
                            <span class="text-sm font-medium text-gray-900">{{ $operation->typeLabel() }}</span>
                            @if ($operation->product)
                                <a href="{{ route('products.show', $operation->product) }}"
                                    class="text-sm text-indigo-600 hover:underline">{{ $operation->product->name }}</a>
                                <span class="text-xs text-gray-500">{{ $operation->product->sku }}</span>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            <span>par {{ $operation->user->name }}</span>
                            <span>via {{ $operation->device->name }}</span>
                            @if ($operation->occurred_at)
                                <span>saisie le {{ $operation->occurred_at->format('d/m/Y H:i') }}</span>
                            @endif
                            <span>reçue le {{ $operation->received_at->format('d/m/Y H:i') }}</span>
                        </div>

                        @if ($operation->type === 'adjustment')
                            <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700 space-y-1">
                                <div>
                                    Comptage : <strong>{{ $payload['counted_quantity'] ?? '?' }}</strong>
                                    @if ($lot)
                                        dans le lot {{ $lot->lot_number }} (stock actuel du lot : {{ $lot->quantity_remaining }})
                                    @elseif ($operation->product)
                                        · stock actuel : <strong>{{ $operation->product->quantity }} {{ $operation->product->unit }}</strong>
                                    @endif
                                </div>
                                @if (! $lot && $operation->product && is_numeric($payload['counted_quantity'] ?? null))
                                    @php $gap = (int) $payload['counted_quantity'] - $operation->product->quantity; @endphp
                                    <div>Écart actuel : <strong>{{ $gap > 0 ? '+' : '' }}{{ $gap }}</strong></div>
                                @endif
                                @if ($payload['comment'] ?? null)
                                    <div>Justification : {{ $payload['comment'] }}</div>
                                @endif
                                @if ($isPending && $operation->movements_since > 0)
                                    <div class="text-amber-700">
                                        {{ $operation->movements_since }} mouvement(s) enregistré(s) sur ce produit depuis le comptage :
                                        le stock a pu évoluer avant ou après la saisie.
                                    </div>
                                @endif
                            </div>
                        @elseif ($operation->type === 'exit')
                            <div class="text-sm text-gray-700">
                                Demandé : <strong>{{ $payload['quantity'] ?? '?' }} {{ $operation->product?->unit }}</strong>
                                @if ($isConflict && $operation->product)
                                    · stock actuel : <strong>{{ $operation->product->quantity }}</strong>
                                @endif
                            </div>
                        @endif

                        @if ($operation->reason)
                            <p class="text-sm text-gray-600">{{ $operation->reason }}</p>
                        @endif

                        @foreach ($operation->notes ?? [] as $note)
                            <p class="text-xs text-gray-500">{{ $note }}</p>
                        @endforeach

                        @if ($isConflict)
                            @can('resolve', $operation)
                                <form method="POST" action="{{ route('sync-operations.resolve', $operation) }}"
                                    class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                                    @csrf
                                    <input type="text" name="comment" maxlength="500"
                                        placeholder="Commentaire (obligatoire pour rejeter)"
                                        class="min-w-64 flex-1 rounded-md border-gray-300 text-sm shadow-sm">
                                    <button name="action" value="partial"
                                        class="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700">Appliquer la quantité disponible</button>
                                    <button name="action" value="replay"
                                        class="rounded-md bg-gray-200 px-3 py-1.5 text-sm text-gray-800 hover:bg-gray-300">Rejouer</button>
                                    <button name="action" value="reject"
                                        class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">Rejeter</button>
                                </form>
                                @if ($operation->product?->is_active ?? false)
                                    <p class="text-xs text-gray-500">
                                        Pour rejouer après coup : enregistre d'abord
                                        <a href="{{ route('movements.create', [$operation->product, 'entry']) }}" class="text-indigo-600 hover:underline">une entrée</a>
                                        ou
                                        <a href="{{ route('movements.create', [$operation->product, 'adjustment']) }}" class="text-indigo-600 hover:underline">un ajustement justifié</a>.
                                    </p>
                                @endif
                            @endcan
                        @elseif ($isPending)
                            @can('validateAdjustment', $operation)
                                <form method="POST" action="{{ route('sync-operations.resolve', $operation) }}"
                                    class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                                    @csrf
                                    <input type="text" name="comment" maxlength="500"
                                        placeholder="Commentaire (obligatoire pour refuser)"
                                        class="min-w-64 flex-1 rounded-md border-gray-300 text-sm shadow-sm">
                                    <button name="action" value="approve"
                                        class="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700">Confirmer</button>
                                    <button name="action" value="refuse"
                                        class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-700">Refuser</button>
                                </form>
                            @else
                                <p class="border-t border-gray-100 pt-3 text-xs text-gray-500">
                                    En attente de validation par un administrateur.
                                </p>
                            @endcan
                        @elseif ($operation->resolved_at)
                            <p class="border-t border-gray-100 pt-3 text-xs text-gray-500">
                                Traitée le {{ $operation->resolved_at->format('d/m/Y H:i') }} par {{ $operation->resolver?->name ?? '—' }}
                            </p>
                        @endif
                    </div>
                @empty
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-500">
                        Rien à traiter pour ces critères.
                    </div>
                @endforelse
            </div>

            {{ $operations->links() }}
        </div>
    </div>
</x-app-layout>