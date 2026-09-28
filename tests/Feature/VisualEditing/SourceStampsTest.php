<?php

namespace Tests\Feature\VisualEditing;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SourceStampsTest extends TestCase
{
    public function test_parts_of_a_list_and_parts_shown_at_times_are_marked()
    {
        $workspace = storage_path('framework/testing/stamps-'.getmypid());
        File::ensureDirectoryExists("{$workspace}/resources/js/pages");
        File::put("{$workspace}/resources/js/pages/Plans.vue", <<<'VUE'
            <template>
                <ul>
                    <li v-for="plan in plans" :key="plan.id"><span>{{ plan.name }}</span></li>
                </ul>
                <p v-if="empty">None yet</p>
                <p v-else>Pick one</p>
                <template v-if="admin"><Button>Add</Button></template>
                <div v-show="open">Menu</div>
                <footer>Always</footer>
            </template>
            VUE);

        try {
            Process::path($workspace)->run([config('builder.preview.locator.node'), config('builder.preview.locator.path')])->throw();
            $stamped = File::get("{$workspace}/resources/js/pages/Plans.vue");
        } finally {
            File::deleteDirectory($workspace);
        }

        $this->assertStringContainsString('<ul data-builder-source="resources/js/pages/Plans.vue:2:5">', $stamped);
        $this->assertStringContainsString('<li data-builder-source="resources/js/pages/Plans.vue:3:9" data-builder-loop v-for', $stamped);
        $this->assertStringContainsString('<span data-builder-source="resources/js/pages/Plans.vue:3:50" data-builder-loop>', $stamped);
        $this->assertStringContainsString('<p data-builder-source="resources/js/pages/Plans.vue:5:5" data-builder-when="either" v-if', $stamped);
        $this->assertStringContainsString('<p data-builder-source="resources/js/pages/Plans.vue:6:5" data-builder-when="either" v-else>', $stamped);
        // A condition on a <template> belongs to what it holds.
        $this->assertStringContainsString('<Button data-builder-instance="resources/js/pages/Plans.vue:7:28" data-builder-when="if">', $stamped);
        $this->assertStringContainsString('<div data-builder-source="resources/js/pages/Plans.vue:8:5" data-builder-when="show" v-show', $stamped);
        $this->assertStringContainsString('<footer data-builder-source="resources/js/pages/Plans.vue:9:5">', $stamped);
    }
}
