<?php

namespace App\Http\Requests;

use App\Models\FeatureRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FeatureRequestCaseCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var FeatureRequest $featureRequest */
        $featureRequest = $this->route('featureRequest');

        return $this->user()->can('requestFeatures', $featureRequest->project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'criterion' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'string', 'in:base,alternate,exception'],
            'note' => ['required', 'string', 'max:1000'],
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
            'note.required' => __('Say what you meant, so the next try gets it right.'),
        ];
    }
}
