<?php

namespace App\Http\Requests;

use App\Models\FeatureRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FollowUpStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var FeatureRequest $featureRequest */
        $featureRequest = $this->route('featureRequest');

        return $this->user()->can('requestFeatures', $featureRequest->project);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:5000'],
            'selection' => ['nullable', 'array:file,line,column,tag,text,area,behavior'],
            'selection.file' => ['required_with:selection', 'string', 'max:500'],
            'selection.line' => ['required_with:selection', 'integer', 'min:1'],
            'selection.column' => ['required_with:selection', 'integer', 'min:1'],
            'selection.tag' => ['required_with:selection', 'string', 'max:100'],
            'selection.text' => ['nullable', 'string', 'max:500'],
            'selection.area' => ['nullable', 'string', 'max:200'],
            // A controller and method, or a route name (see FindBehavior).
            'selection.behavior' => ['nullable', 'string', 'max:300', 'regex:/^[A-Za-z_\\\\][\\w\\\\.@-]*$/'],
            // Pictures that show what the owner means; never SVG, which can hold code.
            'images' => ['nullable', 'list', 'max:'.config('builder.construction.images.max')],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp,gif', 'max:'.config('builder.construction.images.max_kilobytes')],
        ];
    }
}
