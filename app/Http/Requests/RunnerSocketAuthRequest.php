<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RunnerSocketAuthRequest extends FormRequest
{
    /**
     * A runner may listen only on its own channel.
     */
    public function authorize(): bool
    {
        return $this->input('channel_name') === 'private-runner.'.$this->attributes->get('runner');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string'],
        ];
    }
}
