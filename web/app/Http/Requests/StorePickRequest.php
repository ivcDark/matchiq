<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'prediction_outcome_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function outcomeId(): int
    {
        return (int) $this->validated('prediction_outcome_id');
    }
}
