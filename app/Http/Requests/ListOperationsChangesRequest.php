<?php

namespace App\Http\Requests;

use App\Enums\VerificationStatus;
use App\Operations\ChangeOutcome;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOperationsChangesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewOperations');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'outcome' => ['nullable', Rule::enum(ChangeOutcome::class)],
            'verification' => ['nullable', Rule::enum(VerificationStatus::class)],
            'driver' => ['nullable', 'string', 'max:100'],
            'provider' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Get the filters that were given.
     *
     * @return array{project?: string, from?: string, to?: string, outcome?: string, verification?: string, driver?: string, provider?: string, model?: string, reason?: string}
     */
    public function filters(): array
    {
        /** @var array{project?: string, from?: string, to?: string, outcome?: string, verification?: string, driver?: string, provider?: string, model?: string, reason?: string} */
        return array_filter($this->validated(), fn (mixed $value) => $value !== null && $value !== '');
    }
}
