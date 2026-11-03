<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Anomalies
            @if ($newCount > 0)
                <span class="ml-2 rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800">{{ $newCount }} à examiner</span>
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

            <p class="text-sm text-gray-600">
                Le système signale, l'humain décide : aucune opération n'est jamais bloquée automatiquement.
            </p>

            <form method="GET" class="flex flex-wrap gap-2">
                <select name="status" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="" @selected($status === null)>Tous les statuts</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}" @selected($status === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>

                <select name="rule" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Toutes les règles</option>
                    @foreach ($rules as $rule)
                        <option value="{{ $rule->value }}" @selected(request('rule') === $rule->value)>{{ $rule->label() }}</option>
                    @endforeach
                </select>

                <x-secondary-button type="submit">Filtrer</x-secondary-button>
                <a href="{{ route('anomalies.index') }}" class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="space-y-3">
                @forelse ($anomalies as $anomaly)
                    <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-1 text-xs {{ $anomaly->rule_code->badgeClasses() }}"
                                title="{{ $anomaly->rule_code->description() }}">
                                {{ $anomaly->rule_code->label() }}
                            </span>
                            <span class="rounded-full px-2 py-1 text-xs {{ $anomaly->status->badgeClasses() }}">
                                {{ $anomaly->status->label() }}
                            </span>
                            <span class="text-xs text-gray-500">détectée le {{ $anomaly->detected_at->format('d/m/Y H:i') }}</span>
                        </div>

                        <p class="text-sm text-gray-900">{{ $anomaly->message }}</p>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            @if ($anomaly->product)
                                <a href="{{ route('products.show', $anomaly->product) }}"
                                    class="text-indigo-600 hover:underline">{{ $anomaly->product->name }}</a>
                            @endif
                            @if ($anomaly->user)
                                <span>par {{ $anomaly->user->name }}</span>
                            @endif
                            @if ($anomaly->product)
                                <a href="{{ route('movements.index', ['product' => $anomaly->product->id]) }}"
                                    class="text-indigo-600 hover:underline">Historique du produit</a>
                            @endif
                        </div>

                        @if ($anomaly->status === \App\Enums\AnomalyStatus::New)
                            <form method="POST" action="{{ route('anomalies.update', $anomaly) }}"
                                class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="comment" maxlength="500" placeholder="Commentaire (obligatoire pour ignorer)"
                                    class="min-w-64 flex-1 rounded-md border-gray-300 text-sm shadow-sm">
                                <button name="status" value="reviewed"
                                    class="rounded-md bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-700">Marquer examinée</button>
                                <button name="status" value="ignored"
                                    class="rounded-md bg-gray-200 px-3 py-1.5 text-sm text-gray-800 hover:bg-gray-300">Ignorer</button>
                            </form>
                        @else
                            <p class="border-t border-gray-100 pt-3 text-xs text-gray-500">
                                Traitée le {{ $anomaly->reviewed_at?->format('d/m/Y H:i') }}
                                par {{ $anomaly->reviewer?->name ?? '—' }}
                                @if ($anomaly->comment)
                                    · « {{ $anomaly->comment }} »
                                @endif
                            </p>
                        @endif
                    </div>
                @empty
                    <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-500">
                        Aucune anomalie pour ces critères.
                    </div>
                @endforelse
            </div>

            {{ $anomalies->links() }}
        </div>
    </div>
</x-app-layout>