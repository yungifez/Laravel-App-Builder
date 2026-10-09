<?php

namespace App\Http\Requests;

use App\Actions\Previews\ReadPreviewRows;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewRowUpdateRequest extends FormRequest
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
            'table' => ['required', 'string', 'max:64'],
            'row' => ['required', 'string', 'max:191'],
            // What visitors sign in with stays hidden, and so unchanged.
            'column' => ['required', 'string', 'max:64', 'not_regex:'.ReadPreviewRows::HIDDEN],
            'value' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }
}
