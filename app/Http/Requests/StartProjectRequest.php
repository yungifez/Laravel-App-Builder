<?php

namespace App\Http\Requests;

use App\Projects\DesignDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartProjectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:2000'],
            'design' => ['nullable', 'string', Rule::in(array_map(fn (DesignDirection $design) => $design->key, DesignDirection::all()))],
        ];
    }

    /**
     * Get the look the owner picked, if any.
     */
    public function design(): ?DesignDirection
    {
        $key = $this->validated('design');

        return is_string($key) ? DesignDirection::find($key) : null;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose.required' => __('Tell me in a sentence or two what your app is for.'),
            'design.in' => __('Pick one of the looks shown.'),
        ];
    }
}
