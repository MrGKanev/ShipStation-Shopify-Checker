<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderComparisonRequest extends FormRequest
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
            'order_a' => ['nullable', 'required_with:order_b', 'string', 'max:64', 'regex:/\A#?[a-zA-Z0-9_-]+\z/'],
            'order_b' => ['nullable', 'required_with:order_a', 'string', 'max:64', 'regex:/\A#?[a-zA-Z0-9_-]+\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order_a.required_with' => 'Enter two order numbers to compare.',
            'order_b.required_with' => 'Enter two order numbers to compare.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['order_a', 'order_b'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $orderNumber = ltrim(trim($value), '#');
            $this->merge([$field => $orderNumber === '' ? null : $orderNumber]);
        }
    }
}
