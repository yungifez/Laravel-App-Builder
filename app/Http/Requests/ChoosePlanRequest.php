<?php

namespace App\Http\Requests;

use App\Actions\Billing\ListPlans;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChoosePlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. Only a paid plan
     * open for sign-up can be chosen.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $open = collect(app(ListPlans::class)->handle())
            ->filter(fn (array $plan) => $plan['open'] && $plan['price'] > 0)
            ->pluck('key')
            ->all();

        return [
            'plan' => ['required', 'string', Rule::in($open)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.in' => __('That plan is not open yet.'),
        ];
    }
}
