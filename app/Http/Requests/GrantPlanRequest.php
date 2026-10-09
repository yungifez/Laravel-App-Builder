<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantPlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. Only a plan
     * above the one everybody has can be given.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['nullable', 'string', Rule::in(array_slice(array_keys((array) config('billing.plans')), 1))],
            'until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
