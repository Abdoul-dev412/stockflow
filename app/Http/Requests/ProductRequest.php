<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product
            ? $this->user()->can('update', $product)
            : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $sku = $this->input('sku');
        $barcode = $this->input('barcode');

        if (is_string($barcode)) {
            $barcode = trim($barcode);
        }

        $this->merge([
            'sku' => is_string($sku) ? mb_strtoupper(trim($sku)) : $sku,
            'barcode' => $barcode === '' ? null : $barcode,
        ]);
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\-]*$/',
                Rule::unique('products', 'sku')->ignore($product),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('products', 'barcode')->ignore($product),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where(fn ($query) => $query->where(
                    fn ($q) => $q->where('is_active', true)
                        ->when($product, fn ($q) => $q->orWhere('id', $product->category_id))
                )),
            ],
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where(
                    fn ($q) => $q->where('is_active', true)
                        ->when($product?->supplier_id, fn ($q) => $q->orWhere('id', $product->supplier_id))
                )),
            ],
            'unit' => ['required', 'string', 'max:20'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'minimum_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'La référence (SKU) est obligatoire.',
            'sku.unique' => 'Cette référence est déjà utilisée.',
            'sku.regex' => 'La référence ne peut contenir que des lettres, chiffres, points, tirets et underscores.',
            'barcode.unique' => 'Ce code-barres est déjà utilisé.',
            'barcode.regex' => 'Le code-barres ne peut contenir que des lettres, chiffres et tirets.',
            'name.required' => 'Le nom du produit est obligatoire.',
            'category_id.required' => 'Choisis une catégorie.',
            'category_id.exists' => 'Cette catégorie est introuvable ou désactivée.',
            'supplier_id.exists' => 'Ce fournisseur est introuvable ou désactivé.',
            'unit.required' => "L'unité est obligatoire.",
            'purchase_price.required' => "Le prix d'achat est obligatoire.",
            'purchase_price.min' => "Le prix d'achat ne peut pas être négatif.",
            'purchase_price.decimal' => "Le prix d'achat accepte au maximum 2 décimales.",
            'sale_price.min' => 'Le prix de vente ne peut pas être négatif.',
            'sale_price.decimal' => 'Le prix de vente accepte au maximum 2 décimales.',
            'minimum_stock.required' => 'Le seuil minimal est obligatoire.',
            'minimum_stock.integer' => 'Le seuil minimal doit être un nombre entier.',
            'minimum_stock.min' => 'Le seuil minimal ne peut pas être négatif.',
        ];
    }
}
