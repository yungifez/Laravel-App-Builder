<?php

namespace App\Actions\Projects;

use App\Actions\Features\RequestFeature;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;

class ConnectService
{
    public function __construct(
        private RequestFeature $requestFeature,
    ) {}

    /**
     * Keep the keys the owner pasted for an outside service. The first time,
     * the app is changed to use the service; later, only the keys change.
     *
     * @param  array<string, string>  $keys
     */
    public function handle(Project $project, User $owner, string $service, array $keys): ?FeatureRequest
    {
        $connected = in_array($service, $project->connectedServices(), true);

        $project->forceFill(['service_keys' => [...$project->service_keys ?? [], $service => $keys]])->save();

        if ($connected) {
            return null;
        }

        return $this->requestFeature->handle($project, $owner, (string) config("builder.services.{$service}.request"));
    }
}
