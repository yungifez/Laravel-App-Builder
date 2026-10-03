<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SuspendPersonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request. Operators
     * cannot stop each other or themselves.
     */
    public function authorize(): bool
    {
        $person = $this->route('user');

        return $person instanceof User && ! $person->can('viewOperations');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'suspended' => ['required', 'boolean'],
        ];
    }
}
