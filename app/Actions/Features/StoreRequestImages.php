<?php

namespace App\Actions\Features;

use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class StoreRequestImages
{
    /**
     * Keep the pictures an owner attached to a request, on the request
     * images disk under the project, each under a new name. The name the
     * owner gave is kept only to show them.
     *
     * @param  UploadedFile|array<int, UploadedFile>|null  $images  As the request gives them
     * @return list<array{path: string, name: string}>
     */
    public function handle(Project $project, UploadedFile|array|null $images): array
    {
        return array_map(fn (UploadedFile $image) => [
            'path' => (string) $image->storeAs(
                "request-images/{$project->id}",
                Str::uuid7().'.'.($image->extension() ?: 'png'),
                Config::string('builder.construction.images.disk'),
            ),
            'name' => Str::limit($image->getClientOriginalName(), 120, ''),
        ], array_values(Arr::wrap($images)));
    }
}
