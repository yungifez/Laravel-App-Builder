<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\TemplateElement;
use App\VisualEditing\WayfinderCall;
use PHPUnit\Framework\TestCase;

class WayfinderCallTest extends TestCase
{
    protected const PAGE = <<<'VUE'
    <script setup lang="ts">
    import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
    import InviteController from '@/actions/App/Http/Controllers/InviteController';
    import { store } from '@/routes/two-factor/login';
    import { dashboard, type RouteName } from '@/routes';
    import password from '@/routes/password';
    import { send as resend } from '@/routes/verification';
    </script>

    <template>
        <Form v-bind="SecurityController.update.form()">
            <Button>Save</Button>
        </Form>
        <form :action="store.url()"><input type="submit" value="Go" /></form>
        <Link :href="dashboard()">Home</Link>
        <Link :href="password.request()">Forgot?</Link>
        <Button @click="router.post(resend.url())">Send again</Button>
        <Button :form="InviteController.form()">Invite</Button>
        <a href="/help">Help</a>
    </template>

    VUE;

    public function test_it_reads_the_action_or_route_a_part_calls_from_the_files_imports()
    {
        $this->assertCall(['action', 'App\Http\Controllers\Settings\SecurityController@update'], 11, 5);
        $this->assertCall(['route', 'dashboard'], 15, 5);
        $this->assertCall(['route', 'password.request'], 16, 5);
        $this->assertCall(['route', 'verification.send'], 17, 5);
        // An invokable controller is called as itself.
        $this->assertCall(['action', 'App\Http\Controllers\InviteController'], 18, 5);
    }

    public function test_a_button_with_no_call_of_its_own_sends_the_form_it_is_in()
    {
        $this->assertCall(['action', 'App\Http\Controllers\Settings\SecurityController@update'], 12, 9);
        $this->assertCall(['route', 'two-factor.login.store'], 14, 33);
    }

    public function test_a_part_that_calls_nothing_through_wayfinder_has_no_call()
    {
        $this->assertNull(WayfinderCall::in(self::PAGE, TemplateElement::at(self::PAGE, 19, 5)));

        // Without Wayfinder's imports, a call named the same is the app's own.
        $plain = "<script setup>\nimport { store } from './forms';\n</script>\n<template>\n    <Form v-bind=\"store.form()\"><button>Save</button></Form>\n</template>\n";
        $this->assertNull(WayfinderCall::in($plain, TemplateElement::at($plain, 5, 5)));

        // A button set to do something else does not send its form.
        $cancel = "<script setup>\nimport { store } from '@/routes/login';\n</script>\n<template>\n    <Form v-bind=\"store.form()\"><button type=\"button\">Cancel</button></Form>\n</template>\n";
        $this->assertNull(WayfinderCall::in($cancel, TemplateElement::at($cancel, 5, 33)));
    }

    /**
     * @param  array{0: string, 1: string}  $expected
     */
    protected function assertCall(array $expected, int $line, int $column): void
    {
        $element = TemplateElement::at(self::PAGE, $line, $column);
        $this->assertNotNull($element, "No element at {$line}:{$column}");

        $call = WayfinderCall::in(self::PAGE, $element);
        $this->assertSame($expected, [$call?->kind, $call?->name], "At {$line}:{$column}");
    }
}
