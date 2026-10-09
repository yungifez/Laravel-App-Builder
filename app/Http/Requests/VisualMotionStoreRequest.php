<?php

namespace App\Http\Requests;

use App\Models\Preview;
use App\Models\Project;
use App\VisualEditing\MotionClasses;
use App\VisualEditing\SourceLocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class VisualMotionStoreRequest extends FormRequest
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
            'expected' => ['present', 'nullable', 'string', 'max:2000'],
            'revision' => ['required', 'string', 'regex:/^[0-9a-f]{40,64}$/'],
            'motion.entrance' => ['required', Rule::in(MotionClasses::ENTRANCES)],
            'motion.speed' => ['required', Rule::in(MotionClasses::SPEEDS)],
            'motion.wait' => ['required', Rule::in(MotionClasses::WAITS)],
            'motion.hover' => ['required', Rule::in(MotionClasses::HOVERS)],
            'motion.loop' => ['required', Rule::in(MotionClasses::LOOPS)],
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
     * Get the preview the owner changed the motion in.
     */
    public function preview(): Preview
    {
        return Preview::query()->where('uuid', $this->validated('preview'))->firstOrFail();
    }

    /**
     * Get where the element was written.
     */
    public function location(): SourceLocation
    {
        return SourceLocation::parse($this->validated('target'), $this->boolean('instance'));
    }

    /**
     * Get the chosen motion.
     *
     * @return array{entrance: string, speed: string, wait: string, hover: string, loop: string}
     */
    public function motion(): array
    {
        /** @var array{entrance: string, speed: string, wait: string, hover: string, loop: string} $motion */
        $motion = $this->safe()->only(['motion'])['motion'];

        return $motion;
    }
}
