<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Modifier le produit</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-600">
                Quantité actuelle : <strong>{{ $product->quantity }}</strong>. Elle ne se modifie pas ici, mais par un mouvement de stock.
            </p>
            <form method="POST" action="{{ route('products.update', $product) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6">
                @method('PUT')
                @include('products._form')
            </form>
        </div>
    </div>
</x-app-layout>