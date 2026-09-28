<?php

namespace App\Http\Requests;

use App\Models\Preview;
use App\Models\Project;
use App\VisualEditing\ThemeColors;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeColorStoreRequest extends FormRequest
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
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'preview' => ['required', 'integer', Rule::exists('previews', 'id')->where('project_id', $project->id)->where('editable', true)],
            'mode' => ['required', Rule::in(array_keys(ThemeColors::MODES))],
            'token' => ['required', Rule::in(ThemeColors::TOKENS)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'revision' => ['required', 'string', 'regex:/^[0-9a-f]{40,64}$/'],
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
            'color.regex' => __('Pick a colour.'),
        ];
    }

    /**
     * Get the preview the owner changed the colour in.
     */
    public function preview(): Preview
    {
        return Preview::query()->whereKey($this->validated('preview'))->firstOrFail();
    }
}
