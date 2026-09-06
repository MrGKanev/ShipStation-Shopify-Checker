<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OrderTagSearchRequest extends FormRequest
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
            'tag' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if (is_string($start) && is_string($end) && $start > $end) {
                $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        foreach (['tag', 'start_date', 'end_date'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' && $field !== 'tag' ? null : $value]);
            }
        }
    }
}
