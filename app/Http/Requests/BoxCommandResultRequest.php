<?php

namespace App\Http\Requests;

use App\Models\BoxCommand;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BoxCommandResultRequest extends FormRequest
{
    /**
     * Only the runner that holds the command may report on it.
     */
    public function authorize(): bool
    {
        /** @var BoxCommand $command */
        $command = $this->route('command');

        return $command->runner === $this->attributes->get('runner');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exit_code' => ['present', 'nullable', 'integer'],
            'output' => ['present', 'nullable', 'string'],
            'error_output' => ['present', 'nullable', 'string'],
            'timed_out' => ['required', 'boolean'],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'contents' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
