<?php

namespace App\Http\Requests;

use App\Enums\MovementReason;
use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockMovement::class);
    }

    public function rules(): array
    {
        $type = MovementType::tryFrom((string) $this->route('type'));
        $max = StockMovementService::MAX_STOCK;

        if ($type === MovementType::Adjustment) {
            return [
                'counted_quantity' => ['required', 'integer', 'min:0', "max:{$max}"],
                'reason' => ['required', 'string', 'max:500'],
            ];
        }

        $reasons = array_map(fn (MovementReason $reason) => $reason->value, $type?->allowedReasons() ?? []);

        return [
            'quantity' => ['required', 'integer', 'min:1', "max:{$max}"],
            'reason_code' => ['required', Rule::in($reasons)],
            'reason' => [
                'nullable', 'string', 'max:500',
                Rule::requiredIf($this->input('reason_code') === MovementReason::Other->value),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.integer' => 'La quantité doit être un nombre entier.',
            'quantity.min' => 'La quantité doit être strictement positive.',
            'quantity.max' => 'La quantité est trop élevée.',
            'counted_quantity.required' => 'La quantité constatée est obligatoire.',
            'counted_quantity.integer' => 'La quantité constatée doit être un nombre entier.',
            'counted_quantity.min' => 'La quantité constatée ne peut pas être négative.',
            'counted_quantity.max' => 'La quantité constatée est trop élevée.',
            'reason_code.required' => 'Choisis un motif.',
            'reason_code.in' => "Ce motif n'est pas valide pour ce type d'opération.",
            'reason.required' => 'Un commentaire est obligatoire pour cette opération.',
            'reason.max' => 'Le commentaire ne doit pas dépasser 500 caractères.',
        ];
    }
}