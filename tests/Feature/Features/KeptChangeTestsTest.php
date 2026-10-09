<?php

namespace Tests\Feature\Features;

use App\Models\FeatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeptChangeTestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_says_how_many_tests_it_adds_to_keep_it_working()
    {
        $request = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/tests/Feature/InviteTest.php b/tests/Feature/InviteTest.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/tests/Feature/InviteTest.php',
            '@@ -0,0 +1,4 @@',
            "+test('owners can invite people', function () {});",
            "+test('members cannot invite people', function () {});",
            'diff --git a/app/Team.php b/app/Team.php',
            '--- a/app/Team.php',
            '+++ b/app/Team.php',
            '@@ -1 +1 @@',
            '-<?php',
            '+<?php // teams',
        ])."\n"]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.tests_added', 2));
    }
}
