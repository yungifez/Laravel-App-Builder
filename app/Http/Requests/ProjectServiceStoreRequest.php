<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectServiceStoreRequest extends FormRequest
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
     * Get the validation rules that apply to the request: each of the
     * service's fields, checked as the catalogue says.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (config("builder.services.{$this->route('service')}.fields", []) as $name => $field) {
            $rules["keys.{$name}"] = ['required', 'string', 'max:255', ...$field['rules'] ?? []];
        }

        return $rules;
    }

    /**
     * Get custom attributes for validator errors, in the owner's words.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (config("builder.services.{$this->route('service')}.fields", []) as $name => $field) {
            $attributes["keys.{$name}"] = mb_strtolower($field['label']);
        }

        return $attributes;
    }

    /**
     * Get the error messages: a key that does not look right says what it
     * should look like.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (config("builder.services.{$this->route('service')}.fields", []) as $name => $field) {
            $messages["keys.{$name}.regex"] = trim(__('That does not look like your :label.', ['label' => mb_strtolower($field['label'])]).' '.(isset($field['hint']) ? "{$field['hint']}." : ''));
        }

        return $messages;
    }

    /**
     * Get the keys, one per field of the service.
     *
     * @return array<string, string>
     */
    public function keys(): array
    {
        return array_map(trim(...), $this->validated('keys'));
    }
}
