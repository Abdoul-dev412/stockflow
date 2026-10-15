<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nouveau produit</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-600">
                Le stock initial est à 0. Il sera renseigné par une entrée de stock, pour que l'historique explique toujours la quantité.
            </p>
            <form method="POST" action="{{ route('products.store') }}"
                class="bg-white shadow-sm sm:rounded-lg p-6">
                @include('products._form')
            </form>
        </div>
    </div>
</x-app-layout>