<?php

namespace App\Http\Requests;

use App\Models\Preview;
use App\Models\Project;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplatePicture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class VisualPictureStoreRequest extends FormRequest
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
            // An SVG may carry scripts into the app, so it is left out.
            'picture' => ['required', 'file', 'image', 'mimes:'.implode(',', TemplatePicture::EXTENSIONS), 'max:5120'],
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
            'picture.required' => __('Choose a picture to show here.'),
            'picture.image' => __('Choose a photo or picture file.'),
            'picture.mimes' => __('Choose a JPG, PNG, GIF, WebP or AVIF picture.'),
            'picture.max' => __('Choose a picture smaller than 5 MB.'),
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
     * Get the preview the owner changed the picture in.
     */
    public function preview(): Preview
    {
        return Preview::query()->where('uuid', $this->validated('preview'))->firstOrFail();
    }

    /**
     * Get where the picture was written.
     */
    public function location(): SourceLocation
    {
        return SourceLocation::parse($this->validated('target'), $this->boolean('instance'));
    }
}
