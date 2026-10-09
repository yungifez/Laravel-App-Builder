<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeveloperApplicationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'about' => ['required', 'string', 'min:40', 'max:3000'],
            'link' => ['nullable', 'url:http,https', 'max:255'],
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
            'about.min' => __('Tell us a little more: a few sentences about what you have built.'),
            'link.url' => __('Give a full web address, starting with https://.'),
        ];
    }
}
