<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\Secrets;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectNotesUpdateRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'part' => ['required', 'string', 'regex:/^(introduction|section:[^\r\n#]{1,80}|(summary|rules|not_connected):[a-z0-9][a-z0-9_-]{0,59})$/'],
            'body' => ['nullable', 'string', 'max:20000', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Secrets::found($value)) {
                    $fail(__('This looks like a secret key. Keep keys in your app\'s settings, not in its notes.'));
                }
            }],
            'revision' => ['required', 'string', 'regex:/^[0-9a-f]{40,64}$/'],
        ];
    }
}
