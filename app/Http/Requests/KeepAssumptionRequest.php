<?php

namespace App\Http\Requests;

use App\Models\FeatureRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class KeepAssumptionRequest extends FormRequest
{
    /**
     * Only people who may ask for changes may make the app's decisions.
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
            'assumption' => ['required', 'string', 'max:2000'],
        ];
    }
}
