<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stands in for an owner's app that saves in each part of a request, while
 * the boundary rules are tested on what the recorder writes. It is not in
 * a test class, because the recorder leaves out a test's own code.
 */
class BoundaryApp
{
    public const PATH = 'tests/Fixtures/BoundaryApp.php';

    public function counted(User $user): bool
    {
        DB::table('users')->where('id', $user->id)->update(['remember_token' => 'checked']);

        return true;
    }

    public function show(Request $request): JsonResource
    {
        Gate::authorize('counted');

        $request->validate(['name' => [function (string $attribute, mixed $value) {
            DB::table('users')->where('name', $value)->update(['remember_token' => 'validated']);
        }]]);

        DB::table('users')->where('id', $request->user()?->id)->update(['remember_token' => 'handled']);

        return new class($request->user()) extends JsonResource
        {
            /**
             * @return array<string, mixed>
             */
            public function toArray(Request $request): array
            {
                DB::table('users')->where('id', $this->resource->id)->update(['remember_token' => 'rendered']);

                return ['id' => $this->resource->id];
            }
        };
    }
}
