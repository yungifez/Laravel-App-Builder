<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\WayfinderPaths;
use PHPUnit\Framework\TestCase;

class WayfinderPathsTest extends TestCase
{
    protected const FILE = 'resources/js/pages/settings/Profile.vue';

    public function test_the_starter_kit_paths_hold_when_the_app_sets_none()
    {
        $paths = WayfinderPaths::read(null, null);

        $this->assertSame(['kind' => 'action', 'base' => 'App\Http\Controllers\Settings\ProfileController'], $paths->classify('@/actions/App/Http/Controllers/Settings/ProfileController', self::FILE));
        $this->assertSame(['kind' => 'route', 'base' => 'two-factor.login'], $paths->classify('@/routes/two-factor/login', self::FILE));
        $this->assertSame(['kind' => 'route', 'base' => ''], $paths->classify('@/routes', self::FILE));
        $this->assertSame(['kind' => 'route', 'base' => 'password'], $paths->classify('../../routes/password/index', self::FILE));
        $this->assertNull($paths->classify('@/components/ui/button', self::FILE));
        $this->assertNull($paths->classify('@inertiajs/vue3', self::FILE));
    }

    public function test_the_apps_own_aliases_and_wayfinder_folder_are_followed()
    {
        $tsconfig = <<<'JSON'
        {
            "compilerOptions": {
                /* Where imports point. */ "paths": {
                    "~/*": ["./resources/ts/*"]
                }
            }
        }
        JSON;
        $vite = <<<'TS'
        export default defineConfig({
            resolve: { alias: { '#server': fileURLToPath(new URL('./resources/ts/generated', import.meta.url)) } },
            plugins: [wayfinder({ formVariants: true, path: 'resources/ts/generated' })],
        });
        TS;

        $paths = WayfinderPaths::read($tsconfig, $vite);

        $this->assertSame(['kind' => 'action', 'base' => 'App\Http\Controllers\InviteController'], $paths->classify('~/generated/actions/App/Http/Controllers/InviteController', 'resources/ts/pages/Team.vue'));
        $this->assertSame(['kind' => 'route', 'base' => 'teams'], $paths->classify('#server/routes/teams', 'resources/ts/pages/Team.vue'));
    }

    public function test_an_import_that_looks_like_wayfinders_but_is_not_where_the_app_writes_it_is_unknown()
    {
        // "@" stands for another folder, so "@/actions/…" is not Wayfinder's.
        $paths = WayfinderPaths::read('{"compilerOptions": {"paths": {"@/*": ["./resources/ts/*"]}}}', null);

        $this->assertSame(['kind' => 'unknown', 'base' => ''], $paths->classify('@/actions/App/Http/Controllers/ProfileController', self::FILE));
        $this->assertSame(['kind' => 'unknown', 'base' => ''], $paths->classify('@/routes/profile', self::FILE));
        $this->assertNull($paths->classify('@/composables/useActions', self::FILE));
    }
}
