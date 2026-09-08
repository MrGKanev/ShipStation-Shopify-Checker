<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OrderTrackingRequest extends FormRequest
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
            'orders' => ['required', 'string', 'max:4096'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('orders')) {
                return;
            }
            $numbers = $this->parse((string) $this->input('orders'));
            if ($numbers === []) {
                $validator->errors()->add('orders', 'Enter at least one order number.');
            } elseif (count($numbers) > 30) {
                $validator->errors()->add('orders', 'Maximum 30 order numbers at once.');
            } elseif (collect($numbers)->contains(fn (string $number): bool => mb_strlen($number) > 64 || preg_match('/\A[a-zA-Z0-9_-]+\z/', $number) !== 1)) {
                $validator->errors()->add('orders', 'Every order number must contain only letters, numbers, hyphens, or underscores.');
            }
        }];
    }

    /** @return list<string> */
    public function orderNumbers(): array
    {
        return array_values(array_unique($this->parse((string) $this->validated('orders'))));
    }

    /** @return list<string> */
    private function parse(string $input): array
    {
        $tokens = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(fn (string $number): string => ltrim(trim($number), '#'), $tokens));
    }
}
