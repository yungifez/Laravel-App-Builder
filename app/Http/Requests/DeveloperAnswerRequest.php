<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeveloperAnswerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request: an
     * operator, or the developer who took the question.
     */
    public function authorize(): bool
    {
        return $this->user()->can('answer', $this->route('developerReview'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'max:5000'],
            'findings' => ['nullable', 'string', 'max:10000'],
            'guidance' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'summary.required' => __('Write a short answer first.'),
        ];
    }
}
