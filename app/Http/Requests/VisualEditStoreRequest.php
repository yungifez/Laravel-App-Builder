<?php

namespace App\Http\Requests;

use App\Models\Preview;
use App\Models\Project;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TailwindClasses;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class VisualEditStoreRequest extends FormRequest
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
            'target' => ['required', 'string', 'max:600'],
            'instance' => ['boolean'],
            'revision' => ['required', 'string', 'regex:/^[0-9a-f]{40,64}$/'],
            'device' => ['required', Rule::in(TailwindClasses::DEVICES)],
            'changes' => ['required', 'array:'.implode(',', TailwindClasses::PROPERTIES), 'min:1'],
            'changes.*' => ['nullable'],
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
            },
        ];
    }

    /**
     * Get the preview the owner edited in.
     */
    public function preview(): Preview
    {
        return Preview::query()->whereKey($this->validated('preview'))->firstOrFail();
    }

    /**
     * Get where the edited element was written.
     */
    public function location(): SourceLocation
    {
        return SourceLocation::parse($this->validated('target'), $this->boolean('instance'));
    }
}
