<?php

namespace App\Http\Requests;

use App\Models\FeatureRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AnswerQuestionRequest extends FormRequest
{
    /**
     * Only people who may ask for changes may answer questions about them.
     */
    public function authorize(): bool
    {
        /** @var FeatureRequest $featureRequest */
        $featureRequest = $this->route('featureRequest');

        return $this->user()->can('requestFeatures', $featureRequest->project);
    }

    /**
     * Get the validation rules that apply to the request. A missing answer
     * means "you decide".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answer' => ['nullable', 'string', 'max:120'],
            'more_questions' => ['boolean'],
        ];
    }
}
