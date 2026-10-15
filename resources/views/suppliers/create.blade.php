<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nouveau fournisseur</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('suppliers.store') }}"
                class="bg-white shadow-sm sm:rounded-lg p-6">
                @include('suppliers._form')
            </form>
        </div>
    </div>
</x-app-layout>