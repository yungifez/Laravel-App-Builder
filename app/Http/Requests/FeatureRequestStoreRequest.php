<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FeatureRequestStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('requestFeatures', $project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:5000'],
            'selection' => ['nullable', 'array:file,line,column,tag,text,area'],
            'selection.file' => ['required_with:selection', 'string', 'max:500'],
            'selection.line' => ['required_with:selection', 'integer', 'min:1'],
            'selection.column' => ['required_with:selection', 'integer', 'min:1'],
            'selection.tag' => ['required_with:selection', 'string', 'max:100'],
            'selection.text' => ['nullable', 'string', 'max:500'],
            'selection.area' => ['nullable', 'string', 'max:200'],
        ];
    }
}
