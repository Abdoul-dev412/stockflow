<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Journal d'audit</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>
            @endif

            <form method="GET" class="flex flex-wrap items-end gap-2">
                <select name="action" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">Toutes les actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action->value }}" @selected(request('action') === $action->value)>
                            {{ $action->label() }}
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
                <a href="{{ route('audit.index') }}"
                    class="self-center text-sm text-gray-600 hover:underline">Réinitialiser</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Utilisateur</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Action</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Élément</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Détails</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($logs as $log)
                            @php
                                $url = $log->entity_id ? match ($log->entity_type) {
                                    'product' => route('products.show', $log->entity_id),
                                    'category' => route('categories.show', $log->entity_id),
                                    'supplier' => route('suppliers.show', $log->entity_id),
                                    'user' => route('users.edit', $log->entity_id),
                                    default => null,
                                } : null;
                            @endphp
                            <tr class="align-top">
                                <td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3">{{ $log->user?->name ?? 'Système' }}</td>
                                <td class="px-4 py-3">{{ $log->actionLabel() }}</td>
                                <td class="px-4 py-3">
                                    @if ($url)
                                        <a href="{{ $url }}" class="text-indigo-600 hover:underline">
                                            {{ $log->entityLabel() }} n° {{ $log->entity_id }}
                                        </a>
                                    @else
                                        {{ $log->entityLabel() }}{{ $log->entity_id ? ' n° '.$log->entity_id : '' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 space-y-0.5">
                                    @foreach ($log->detailLines() as $line)
                                        <div>{{ $line }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                    Aucune entrée d'audit.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $logs->links() }}
        </div>
    </div>
</x-app-layout>