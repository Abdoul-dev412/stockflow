<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $supplier->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 grid gap-3 sm:grid-cols-2 text-sm text-gray-700">
                <div><span class="text-gray-500">Contact :</span> {{ $supplier->contact_name ?: '—' }}</div>
                <div><span class="text-gray-500">Téléphone :</span> {{ $supplier->phone ?: '—' }}</div>
                <div><span class="text-gray-500">Email :</span> {{ $supplier->email ?: '—' }}</div>
                <div><span class="text-gray-500">Délai de livraison :</span> {{ $supplier->lead_time_days }} jours</div>
                <div class="sm:col-span-2"><span class="text-gray-500">Adresse :</span> {{ $supplier->address ?: '—' }}</div>
                <div class="sm:col-span-2"><span class="text-gray-500">Notes :</span> {{ $supplier->notes ?: '—' }}</div>
                <div><span class="text-gray-500">Statut :</span> {{ $supplier->is_active ? 'actif' : 'inactif' }}</div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">SKU</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Produit</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Quantité</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            <tr>
                                <td class="px-4 py-3">{{ $product->sku }}</td>
                                <td class="px-4 py-3">{{ $product->name }}</td>
                                <td class="px-4 py-3">{{ $product->quantity }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-6 text-center text-gray-500">
                                    Aucun produit associé à ce fournisseur.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}

            <a href="{{ route('suppliers.index') }}" class="text-sm text-gray-600 hover:underline">
                ← Retour aux fournisseurs
            </a>
        </div>
    </div>
</x-app-layout>