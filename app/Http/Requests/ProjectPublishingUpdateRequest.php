<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Publishing\PublicAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectPublishingUpdateRequest extends FormRequest
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
            'deploy_remote' => ['required', 'string', 'max:2000'],
            'deploy_branch' => ['required', 'string', 'max:100', 'regex:/^(?!-)(?!.*\.\.)(?!.*\/\/)[A-Za-z0-9._\/-]+(?<![\/.])$/'],
            'live_url' => ['nullable', 'url', 'max:2000'],
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
                if (! self::acceptableRemote((string) $this->input('deploy_remote'))) {
                    $validator->errors()->add('deploy_remote', __('Use the repository address from your Git host, starting with https:// or git@.'));
                }

                if ($this->filled('live_url') && ! self::acceptableAddress((string) $this->input('live_url'))) {
                    $validator->errors()->add('live_url', __('Use your app\'s web address, starting with https://.'));
                }
            },
        ];
    }

    /**
     * Determine if Git may push to the remote. Only HTTPS and SSH remotes
     * are accepted, so a remote cannot be read as a Git option or run a
     * command through a transport helper. Local paths are for development.
     */
    public static function acceptableRemote(string $remote): bool
    {
        if (preg_match('/\s/', $remote) === 1) {
            return false;
        }

        if (preg_match('#^(https://[^/]+/|ssh://[^/]+/|git@[A-Za-z0-9.-]+:)[^:]+$#', $remote) === 1) {
            return true;
        }

        return config('builder.publishing.allow_local_remotes') && str_starts_with($remote, '/') && ! str_contains($remote, '..');
    }

    /**
     * Determine if the app's address may be checked after a publish. Only
     * HTTPS addresses on a named host are accepted, so the checks cannot
     * be pointed at the control plane's own network. Local addresses are
     * for development.
     */
    public static function acceptableAddress(string $address): bool
    {
        $host = (string) parse_url($address, PHP_URL_HOST);

        if (config('builder.publishing.allow_local_remotes') && preg_match('#^https?://#', $address) === 1) {
            return $host !== '';
        }

        return app(PublicAddress::class)->addresses($address) !== [];
    }
}
