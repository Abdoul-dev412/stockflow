<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier
            ? $this->user()->can('update', $supplier)
            : $this->user()->can('create', Supplier::class);
    }

    protected function prepareForValidation(): void
    {
        // « +226 70 12 34 56 » devient « +22670123456 »
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => preg_replace('/[\s.\-()]/', '', $this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom commercial est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 150 caractères.',
            'email.email' => "L'adresse email n'est pas valide.",
            'phone.regex' => 'Le téléphone doit être au format international, par exemple +22670123456.',
            'lead_time_days.required' => 'Le délai de livraison est obligatoire.',
            'lead_time_days.integer' => 'Le délai doit être un nombre entier de jours.',
            'lead_time_days.min' => 'Le délai ne peut pas être négatif.',
            'lead_time_days.max' => 'Le délai ne peut pas dépasser 365 jours.',
        ];
    }
}
