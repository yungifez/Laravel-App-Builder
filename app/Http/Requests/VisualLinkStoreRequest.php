<?php

namespace App\Http\Requests;

use App\Models\Preview;
use App\Models\Project;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class VisualLinkStoreRequest extends FormRequest
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
            'preview' => ['bail', 'required', 'uuid', Rule::exists('previews', 'uuid')->where('project_id', $project->id)->where('editable', true)],
            'target' => ['required', 'string', 'max:600'],
            'instance' => ['boolean'],
            'before' => ['required', 'string', 'max:2000'],
            'href' => ['required', 'string', 'max:2000'],
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
            'href.required' => __('Type where this link goes.'),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                try {
                    SourceLocation::parse((string) $this->input('target'));
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('target', __('That part of the page cannot be found.'));
                }

                if (is_string($this->input('href')) && ! TemplateLink::allowed(trim($this->input('href')))) {
                    $validator->errors()->add('href', __('Start with "/" for a page of your app, or "https://" for a website.'));
                }
            },
        ];
    }

    /**
     * Get the preview the owner changed the link in.
     */
    public function preview(): Preview
    {
        return Preview::query()->where('uuid', $this->validated('preview'))->firstOrFail();
    }

    /**
     * Get where the link was written.
     */
    public function location(): SourceLocation
    {
        return SourceLocation::parse($this->validated('target'), $this->boolean('instance'));
    }
}
