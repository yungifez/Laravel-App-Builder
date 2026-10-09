<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectNotesDraftStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $this->user()->can('update', $project);
    }

    /**
     * Get the validation rules that apply to the request: the parts of the
     * draft the owner checked and ticked.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'boolean'],
            'areas' => ['present', 'array', 'max:50'],
            'areas.*' => ['string', 'max:60'],
        ];
    }

    /**
     * Get the messages the owner reads.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose.required' => __('Tick the parts that are right first.'),
        ];
    }
}
