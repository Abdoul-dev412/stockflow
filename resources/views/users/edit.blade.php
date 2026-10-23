<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Modifier le compte</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-form-error />
            <form method="POST" action="{{ route('users.update', $user) }}"
                class="bg-white shadow-sm sm:rounded-lg p-6">
                @method('PUT')
                @include('users._form')
            </form>
        </div>
    </div>
</x-app-layout>