<?php

namespace App\Http\Requests;

use App\Projects\DesignDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartProjectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Named from the idea when left empty.
            'name' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:2000'],
            'design' => ['nullable', 'string', Rule::in(array_map(fn (DesignDirection $design) => $design->key, DesignDirection::all()))],
            // What the first version includes, from a starter the owner
            // picked and kept.
            'includes' => ['nullable', 'list', 'max:12'],
            'includes.*' => ['nullable', 'string', 'max:300'],
            // A sketch or screenshot of what the owner has in mind.
            'images' => ['nullable', 'list', 'max:'.config('builder.construction.images.max')],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp,gif', 'max:'.config('builder.construction.images.max_kilobytes')],
        ];
    }

    /**
     * Get the look the owner picked, if any.
     */
    public function design(): ?DesignDirection
    {
        $key = $this->validated('design');

        return is_string($key) ? DesignDirection::find($key) : null;
    }

    /**
     * Get what the first version includes, as the owner kept it.
     *
     * @return list<string>
     */
    public function includes(): array
    {
        return array_values(array_filter(array_map(fn (mixed $item) => trim((string) $item), $this->validated('includes') ?? [])));
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose.required' => __('Tell me in a sentence or two what your app is for.'),
            'design.in' => __('Pick one of the looks shown.'),
        ];
    }
}
