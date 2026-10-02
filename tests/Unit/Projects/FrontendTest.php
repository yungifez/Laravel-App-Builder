<?php

namespace Tests\Unit\Projects;

use App\Projects\Frontend;
use Tests\TestCase;

class FrontendTest extends TestCase
{
    /**
     * @param  array<string, string>  $packages
     */
    protected function manifest(string $section, array $packages): string
    {
        return (string) json_encode([$section => $packages]);
    }

    public function test_each_app_is_named_by_the_packages_it_requires()
    {
        $inertia = $this->manifest('require', ['laravel/framework' => '^13', 'inertiajs/inertia-laravel' => '^3']);

        $this->assertSame('inertia-vue', Frontend::detect($inertia, $this->manifest('dependencies', ['@inertiajs/vue3' => '^3']))->key);
        $this->assertSame('inertia-react', Frontend::detect($inertia, $this->manifest('devDependencies', ['@inertiajs/react' => '^3']))->key);

        $livewire = Frontend::detect($this->manifest('require', ['laravel/framework' => '^13', 'livewire/livewire' => '^4']), null);
        $this->assertSame(['livewire', 'Livewire', ['resources/views/']], [$livewire->key, $livewire->label, $livewire->pages]);
    }

    public function test_an_app_no_stack_matches_is_still_a_laravel_app()
    {
        // Inertia on the server with no client adapter is Blade as far as anyone can tell.
        $this->assertSame('blade', Frontend::detect($this->manifest('require', ['laravel/framework' => '^13', 'inertiajs/inertia-laravel' => '^3']), null)->key);
        $this->assertSame('blade', Frontend::detect('not json', '')->key);

        config(['builder.frontends' => []]);
        $this->assertSame(['unknown', []], [Frontend::detect(null, null)->key, Frontend::detect(null, null)->pages]);
    }
}
