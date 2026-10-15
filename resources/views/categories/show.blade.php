<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $category->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <p class="text-gray-700">{{ $category->description ?: 'Aucune description.' }}</p>
                <p class="mt-2 text-sm text-gray-500">
                    Statut : {{ $category->is_active ? 'active' : 'inactive' }}
                </p>
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
                                    Aucun produit dans cette catégorie.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}

            <a href="{{ route('categories.index') }}" class="text-sm text-gray-600 hover:underline">
                ← Retour aux catégories
            </a>
        </div>
    </div>
</x-app-layout>