<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('correct', $this->route('movement'));
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Une correction doit être justifiée par un commentaire.',
            'reason.max' => 'Le commentaire ne doit pas dépasser 500 caractères.',
        ];
    }
}