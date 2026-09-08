<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PackingSlipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('order_number'))) {
            $this->merge(['order_number' => ltrim(trim((string) $this->input('order_number')), '#')]);
        }
    }
}
