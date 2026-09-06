<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrderBatchLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'orders' => ['required', 'string', 'max:4096'],
            'mode' => ['required', 'string', Rule::in(['both', 'shipstation', 'shopify'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'orders.required' => 'Enter at least one order number.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('orders')) {
                return;
            }

            $orderNumbers = $this->parseOrderNumbers((string) $this->input('orders'));

            if ($orderNumbers === []) {
                $validator->errors()->add('orders', 'Enter at least one order number.');

                return;
            }

            if (count($orderNumbers) > 50) {
                $validator->errors()->add('orders', 'Maximum 50 order numbers at once.');

                return;
            }

            foreach ($orderNumbers as $orderNumber) {
                if (mb_strlen($orderNumber) > 64 || preg_match('/\A[a-zA-Z0-9_-]+\z/', $orderNumber) !== 1) {
                    $validator->errors()->add('orders', 'Every order number must contain only letters, numbers, hyphens, or underscores.');

                    return;
                }
            }
        }];
    }

    /** @return list<string> */
    public function orderNumbers(): array
    {
        return array_values(array_unique($this->parseOrderNumbers((string) $this->validated('orders'))));
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('mode')) {
            $this->merge(['mode' => 'both']);
        }
    }

    /** @return list<string> */
    private function parseOrderNumbers(string $input): array
    {
        $tokens = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            fn (string $orderNumber): string => ltrim(trim($orderNumber), '#'),
            $tokens,
        ));
    }
}
