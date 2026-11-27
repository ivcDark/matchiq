<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MatchIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'league' => ['nullable'],
            'league.*' => ['string'],
            'date' => ['nullable', 'string'],
            'period' => ['nullable', 'string'],
        ];
    }

    public function leagueInput(): mixed
    {
        return $this->query('league');
    }

    public function dateInput(): ?string
    {
        return $this->filled('date') ? (string) $this->query('date') : null;
    }

    public function periodInput(): ?string
    {
        return $this->filled('period') ? (string) $this->query('period') : null;
    }
}
