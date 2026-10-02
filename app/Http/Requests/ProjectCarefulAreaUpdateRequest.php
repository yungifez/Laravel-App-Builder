<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectCarefulAreaUpdateRequest extends FormRequest
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
            'area' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'max:60'],
            'careful' => ['required', 'boolean'],
        ];
    }
}
