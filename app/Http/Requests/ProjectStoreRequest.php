<?php

namespace App\Http\Requests;

use App\Rules\SupportedApplication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectStoreRequest extends FormRequest
{
    /**
     * Server paths are trusted operator input, never an owner's upload.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewOperations');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'source_path' => ['required', 'string', 'max:1024', new SupportedApplication],
        ];
    }
}
