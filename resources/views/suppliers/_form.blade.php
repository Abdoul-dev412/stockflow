@csrf
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <x-input-label for="name" value="Nom commercial" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
            :value="old('name', $supplier->name ?? '')" required autofocus />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contact_name" value="Nom du contact (facultatif)" />
        <x-text-input id="contact_name" name="contact_name" type="text" class="mt-1 block w-full"
            :value="old('contact_name', $supplier->contact_name ?? '')" />
        <x-input-error :messages="$errors->get('contact_name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="email" value="Email (facultatif)" />
        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
            :value="old('email', $supplier->email ?? '')" />
        <x-input-error :messages="$errors->get('email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="phone" value="Téléphone, format international (facultatif)" />
        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
            placeholder="+22670123456" :value="old('phone', $supplier->phone ?? '')" />
        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="lead_time_days" value="Délai de livraison (jours)" />
        <x-text-input id="lead_time_days" name="lead_time_days" type="number" min="0" max="365"
            class="mt-1 block w-full"
            :value="old('lead_time_days', $supplier->lead_time_days ?? 7)" required />
        <x-input-error :messages="$errors->get('lead_time_days')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="address" value="Adresse (facultative)" />
        <textarea id="address" name="address" rows="2"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('address', $supplier->address ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('address')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="notes" value="Notes internes (facultatives)" />
        <textarea id="notes" name="notes" rows="3"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('notes', $supplier->notes ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>Enregistrer</x-primary-button>
    <a href="{{ route('suppliers.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
</div>