<?php

namespace App\Http\Requests;

use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-administration') ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => ['nullable', 'string', 'min:12', 'confirmed'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => ['integer', 'distinct', 'exists:stores,id'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('role')) {
                return;
            }

            /** @var User $user */
            $user = $this->route('user');
            $removesFinalAdministrator = $user->role === UserRole::Admin
                && $this->string('role')->toString() !== UserRole::Admin->value
                && User::query()->where('role', UserRole::Admin)->count() === 1;

            if ($removesFinalAdministrator) {
                $validator->errors()->add('role', 'The final administrator cannot be demoted.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower($this->string('email')->trim()->toString()),
        ]);
    }
}
