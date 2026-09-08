<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TagPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('run-audits') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']];
    }
}
