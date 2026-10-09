<?php

namespace Tests\Unit;

use App\Features\AppBoundaries;
use App\Features\BoundaryCode;
use Tests\TestCase;

class BoundaryCodeTest extends TestCase
{
    /**
     * Every line of the code, as a new file adds them.
     *
     * @return list<int>
     */
    protected function all(string $code): array
    {
        return range(1, substr_count($code, "\n") + 1);
    }

    public function test_a_policy_that_saves_and_calls_out_is_found_wherever_its_method_is()
    {
        $code = <<<'PHP'
            <?php

            namespace App\Policies;

            use Illuminate\Support\Facades\Http;

            class PostPolicy
            {
                public function view($user, $post): bool
                {
                    $post->increment('views');
                    Http::post('https://stats.example.com');

                    return $post->published;
                }

                protected function helper($post): void
                {
                    $post->save();
                }
            }
            PHP;

        $this->assertSame([
            ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'what' => 'save', 'at' => 'app/Policies/PostPolicy.php:11', 'in' => 'App\Policies\PostPolicy::view'],
            ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'what' => 'http', 'at' => 'app/Policies/PostPolicy.php:12', 'in' => 'App\Policies\PostPolicy::view'],
        ], BoundaryCode::read('app/Policies/PostPolicy.php', $code, $this->all($code)));

        // Only the lines the change added count.
        $this->assertSame([], BoundaryCode::read('app/Policies/PostPolicy.php', $code, [14]));
    }

    public function test_a_form_request_that_sends_while_checking_the_input_is_found()
    {
        $code = <<<'PHP'
            <?php

            namespace App\Http\Requests;

            use App\Mail\OrderReceived;
            use Illuminate\Foundation\Http\FormRequest;
            use Illuminate\Support\Facades\Mail;
            use Illuminate\Validation\Rule;

            class StoreOrderRequest extends FormRequest
            {
                public function rules(): array
                {
                    return ['email' => ['required', Rule::unique('users')]];
                }

                protected function passedValidation(): void
                {
                    Mail::to($this->user())->send(new OrderReceived);
                    dispatch(new \App\Jobs\Charge);
                }
            }
            PHP;

        $this->assertSame([
            ['kind' => AppBoundaries::CHANGED_WHILE_VALIDATING, 'what' => 'mail', 'at' => 'app/Http/Requests/StoreOrderRequest.php:19', 'in' => 'App\Http\Requests\StoreOrderRequest::passedValidation'],
            ['kind' => AppBoundaries::CHANGED_WHILE_VALIDATING, 'what' => 'job', 'at' => 'app/Http/Requests/StoreOrderRequest.php:20', 'in' => 'App\Http\Requests\StoreOrderRequest::passedValidation'],
        ], BoundaryCode::read('app/Http/Requests/StoreOrderRequest.php', $code, $this->all($code)));
    }

    public function test_a_resource_that_saves_while_building_the_answer_is_found()
    {
        $code = <<<'PHP'
            <?php

            namespace App\Http\Resources;

            use Illuminate\Http\Resources\Json\JsonResource;

            class PostResource extends JsonResource
            {
                public function toArray($request): array
                {
                    $this->resource->update(['seen_at' => now()]);

                    return ['id' => $this->id];
                }
            }
            PHP;

        $this->assertSame([
            ['kind' => AppBoundaries::CHANGED_WHILE_RENDERING, 'what' => 'save', 'at' => 'app/Http/Resources/PostResource.php:11', 'in' => 'App\Http\Resources\PostResource::toArray'],
        ], BoundaryCode::read('app/Http/Resources/PostResource.php', $code, $this->all($code)));
    }

    public function test_a_provider_that_queries_at_start_is_found_but_what_it_registers_for_later_is_not()
    {
        $code = <<<'PHP'
            <?php

            namespace App\Providers;

            use App\Models\Category;
            use Illuminate\Support\Facades\Gate;
            use Illuminate\Support\Facades\View;
            use Illuminate\Support\ServiceProvider;

            class AppServiceProvider extends ServiceProvider
            {
                public function boot(): void
                {
                    View::share('categories', Category::all());
                    View::composer('nav', fn ($view) => $view->with('categories', Category::all()));
                    Gate::define('admin', fn ($user) => $user->is_admin);
                }
            }
            PHP;

        $this->assertSame([
            ['kind' => AppBoundaries::CHANGED_WHILE_BOOTING, 'what' => 'query', 'at' => 'app/Providers/AppServiceProvider.php:14', 'in' => 'App\Providers\AppServiceProvider::boot'],
        ], BoundaryCode::read('app/Providers/AppServiceProvider.php', $code, $this->all($code)));
    }

    public function test_other_classes_reads_and_code_that_is_not_php_are_left_alone()
    {
        $controller = <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            class PostController
            {
                public function store($request)
                {
                    \App\Models\Post::create($request->validated());
                }
            }
            PHP;
        $policy = <<<'PHP'
            <?php

            namespace App\Policies;

            class PostPolicy
            {
                public function update($user, $post): bool
                {
                    return $post->team->members()->where('user_id', $user->id)->exists();
                }
            }
            PHP;

        $this->assertSame([], BoundaryCode::read('app/Http/Controllers/PostController.php', $controller, $this->all($controller)));
        $this->assertSame([], BoundaryCode::read('app/Policies/PostPolicy.php', $policy, $this->all($policy)));
        $this->assertSame([], BoundaryCode::read('app/Policies/PostPolicy.php', '<?php class {', [1]));
    }

    public function test_a_patch_is_read_file_by_file_as_the_change_leaves_it()
    {
        $patch = <<<'DIFF'
            diff --git a/app/Policies/PostPolicy.php b/app/Policies/PostPolicy.php
            --- a/app/Policies/PostPolicy.php
            +++ b/app/Policies/PostPolicy.php
            @@ -5,3 +5,4 @@
                 public function view($user, $post): bool
                 {
            +        $post->increment('views');
                     return true;
            diff --git a/tests/Feature/PostTest.php b/tests/Feature/PostTest.php
            --- a/tests/Feature/PostTest.php
            +++ b/tests/Feature/PostTest.php
            @@ -1,1 +1,2 @@
             <?php
            +$post->save();
            DIFF;
        $code = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool\n    {\n        \$post->increment('views');\n        return true;\n    }\n}\n";
        $read = [];

        $found = BoundaryCode::inPatch($patch, function (string $path) use (&$read, $code) {
            $read[] = $path;

            return $code;
        });

        $this->assertSame(['app/Policies/PostPolicy.php'], $read);
        $this->assertSame(['app/Policies/PostPolicy.php:7'], array_column($found['read'], 'at'));
        // Before the change the policy had no save.
        $this->assertSame([], $found['before']);
    }

    public function test_what_the_file_had_before_the_change_is_read_from_the_file_and_its_diff()
    {
        // The change moved the save down a line, under a new comment.
        $patch = <<<'DIFF'
            diff --git a/app/Policies/PostPolicy.php b/app/Policies/PostPolicy.php
            --- a/app/Policies/PostPolicy.php
            +++ b/app/Policies/PostPolicy.php
            @@ -6,3 +6,4 @@
                 {
            -        $post->increment('views');
            +        // Count the visit.
            +        $post->increment('views');
                     return true;
            DIFF;
        $code = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool\n    {\n        // Count the visit.\n        \$post->increment('views');\n        return true;\n    }\n}\n";

        $found = BoundaryCode::inPatch($patch, fn () => $code);

        $this->assertSame(['app/Policies/PostPolicy.php:8'], array_column($found['read'], 'at'));
        $this->assertSame(['app/Policies/PostPolicy.php:7'], array_column($found['before'], 'at'));
        $this->assertSame(BoundaryCode::identity($found['read'][0]), BoundaryCode::identity($found['before'][0]));
        $this->assertSame(BoundaryCode::identity(['kind' => 'changed_while_authorizing', 'what' => 'update posts', 'in' => 'App\Policies\PostPolicy::view']), BoundaryCode::identity($found['before'][0]));
    }
}
