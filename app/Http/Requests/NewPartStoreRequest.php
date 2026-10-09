<?php

namespace App\Http\Requests;

use App\VisualEditing\NewPart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class NewPartStoreRequest extends VisualPartRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'part' => ['required', 'string', Rule::in(array_keys(NewPart::MARKUP))],
        ];
    }
}
