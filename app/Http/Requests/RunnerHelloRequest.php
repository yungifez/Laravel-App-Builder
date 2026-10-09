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
            // The HTTPS port that leads to its previews, when it has one.
            'preview_door' => ['nullable', 'array:port,pin,key'],
            'preview_door.port' => ['required_with:preview_door', 'integer', 'between:1,65535'],
            'preview_door.pin' => ['required_with:preview_door', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/'],
            'preview_door.key' => ['required_with:preview_door', 'string', 'regex:/^[A-Za-z0-9_-]{32,128}$/'],
        ];
    }
}
