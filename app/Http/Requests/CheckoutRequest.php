<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name_on_card' => ['required', 'string', 'max:255'],
            'card_number' => ['required', 'digits:16'],
        ];
    }

    /**
     * Accept card numbers typed with spaces.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'card_number' => preg_replace('/\s+/', '', (string) $this->input('card_number')),
        ]);
    }
}
