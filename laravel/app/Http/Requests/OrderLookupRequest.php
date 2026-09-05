<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['nullable', 'string', 'max:64', 'regex:/\A#?[a-zA-Z0-9_-]+\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('order_number')) {
            $orderNumber = ltrim($this->string('order_number')->trim()->toString(), '#');

            $this->merge([
                'order_number' => $orderNumber === '' ? null : $orderNumber,
            ]);
        }
    }
}
