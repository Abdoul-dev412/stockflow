@csrf
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-input-label for="sku" value="Référence (SKU)" />
        <x-text-input id="sku" name="sku" type="text" class="mt-1 block w-full uppercase"
            :value="old('sku', $product->sku ?? '')" required autofocus />
        <x-input-error :messages="$errors->get('sku')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="barcode" value="Code-barres (facultatif)" />
        <x-text-input id="barcode" name="barcode" type="text" class="mt-1 block w-full"
            :value="old('barcode', $product->barcode ?? '')" />
        <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="name" value="Nom du produit" />
        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
            :value="old('name', $product->name ?? '')" required />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="description" value="Description (facultative)" />
        <textarea id="description" name="description" rows="3"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">{{ old('description', $product->description ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="category_id" value="Catégorie" />
        <select id="category_id" name="category_id" required
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="">Choisir…</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}"
                    @selected(old('category_id', $product->category_id ?? null) == $category->id)>
                    {{ $category->name }}{{ $category->is_active ? '' : ' (inactive)' }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="supplier_id" value="Fournisseur principal (facultatif)" />
        <select id="supplier_id" name="supplier_id"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="">Aucun</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}"
                    @selected(old('supplier_id', $product->supplier_id ?? null) == $supplier->id)>
                    {{ $supplier->name }}{{ $supplier->is_active ? '' : ' (inactif)' }}
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('supplier_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="unit" value="Unité" />
        <x-text-input id="unit" name="unit" type="text" list="unit-suggestions"
            class="mt-1 block w-full" :value="old('unit', $product->unit ?? 'unité')" required />
        <datalist id="unit-suggestions">
            <option value="unité"><option value="sac"><option value="boîte">
            <option value="litre"><option value="kg"><option value="plateau">
        </datalist>
        <x-input-error :messages="$errors->get('unit')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="minimum_stock" value="Seuil minimal de stock" />
        <x-text-input id="minimum_stock" name="minimum_stock" type="number" min="0" step="1"
            class="mt-1 block w-full" :value="old('minimum_stock', $product->minimum_stock ?? 0)" required />
        <x-input-error :messages="$errors->get('minimum_stock')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="purchase_price" value="Prix d'achat unitaire" />
        <x-text-input id="purchase_price" name="purchase_price" type="number" min="0" step="0.01"
            class="mt-1 block w-full" :value="old('purchase_price', $product->purchase_price ?? '')" required />
        <x-input-error :messages="$errors->get('purchase_price')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="sale_price" value="Prix de vente unitaire (facultatif)" />
        <x-text-input id="sale_price" name="sale_price" type="number" min="0" step="0.01"
            class="mt-1 block w-full" :value="old('sale_price', $product->sale_price ?? '')" />
        <x-input-error :messages="$errors->get('sale_price')" class="mt-2" />
    </div>

    <div class="sm:col-span-2 space-y-2 rounded-md bg-gray-50 p-4"
        x-data="{ tracks: @js((bool) old('tracks_lots', $product->tracks_lots ?? false)) }">
        <p class="text-sm font-medium text-gray-700">Suivi par lots</p>

        @if ($lotsLocked ?? false)
            <p class="text-sm text-gray-600">
                Ce réglage est verrouillé : ce produit a déjà des mouvements.
                Il est actuellement
                <strong>{{ $product->tracks_lots ? 'suivi par lots' : 'non suivi par lots' }}</strong>{{ $product->tracks_lots ? ($product->is_perishable ? ', périssable' : ', non périssable') : '' }}.
            </p>
        @else
            <input type="hidden" name="tracks_lots" value="0">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="tracks_lots" value="1" x-model="tracks"
                    class="rounded border-gray-300 text-indigo-600 shadow-sm">
                Suivre ce produit par lots (numéro de lot à chaque entrée, sorties en FEFO)
            </label>

            <div x-show="tracks" class="ml-6">
                <input type="hidden" name="is_perishable" value="0">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="is_perishable" value="1"
                        @checked(old('is_perishable', $product->is_perishable ?? false))
                        class="rounded border-gray-300 text-indigo-600 shadow-sm">
                    Produit périssable : la date de péremption est obligatoire
                </label>
            </div>

            <p class="text-xs text-gray-500">
                Ce choix ne pourra plus être modifié dès le premier mouvement de stock.
            </p>
        @endif
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <x-primary-button>Enregistrer</x-primary-button>
    <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
</div>