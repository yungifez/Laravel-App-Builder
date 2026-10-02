<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RunnerHelloRequest extends FormRequest
{
    /**
     * Any runner with a valid token may say hello.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // An IP address or a host name, without a scheme or port.
            'service_host' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:-]+$/'],
        ];
    }
}
