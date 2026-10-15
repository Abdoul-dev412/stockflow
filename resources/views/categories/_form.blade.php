@csrf
<div>
    <x-input-label for="name" value="Nom" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
        :value="old('name', $category->name ?? '')" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="description" value="Description (facultative)" />
    <textarea id="description" name="description" rows="3"
        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description', $category->description ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>Enregistrer</x-primary-button>
    <a href="{{ route('categories.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
</div>