<?php

namespace Tests\Feature\Previews;

use App\Models\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FormFillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.preview.domain' => 'preview.test', 'builder.preview.public_port' => null, 'app.url' => 'http://builder.test']);
    }

    public function test_every_page_of_a_preview_carries_the_form_filler_for_the_builder_only()
    {
        Http::fake(['http://127.0.0.1:20001/*' => Http::response('<html><body><form><input name="name"></form></body></html>', 200, ['Content-Type' => 'text/html'])]);

        foreach ([Preview::factory()->ready(), Preview::factory()->editable()->ready()] as $factory) {
            $preview = $factory->create(['session_hash' => hash('sha256', 'secret-value'), 'session_expires_at' => now()->addHour()]);

            $page = (string) $this->call('GET', "http://{$preview->host}.preview.test/join", [], ['builder_preview' => 'secret-value'], [], ['HTTP_COOKIE' => 'builder_preview=secret-value'])->getContent();

            // The script knows the builder's address, and acts on no other.
            $this->assertStringContainsString('<script data-builder-origin="http://builder.test">', $page);
            $this->assertStringContainsString("type: 'fields'", $page);
            $this->assertStringContainsString('event.origin !== origin', $page);
            $this->assertStringContainsString('window.parent === window', $page);
            // The app's own page is kept whole.
            $this->assertStringContainsString('<input name="name">', $page);
        }
    }

    public function test_the_filler_never_tells_the_builder_what_a_field_holds()
    {
        $script = (string) file_get_contents(resource_path('preview-tools/fill.js'));

        // Every message to the builder is one of these, with a number.
        preg_match_all('/send\(\{[^}]*\}\)/', $script, $messages);
        $this->assertNotEmpty($messages[0]);

        foreach ($messages[0] as $message) {
            $this->assertMatchesRegularExpression("/^send\(\{ type: '(fields|filled)', (empty|fields)(: fill\(\))? \}\)$/", $message, $message);
        }

        // A password is made where the app asks for a new one, and goes into the field and nowhere else.
        $this->assertStringContainsString('new-password', $script);
        $this->assertSame(1, substr_count($script, 'secret()'));
        $this->assertSame(1, substr_count($script, 'secrets.get('));
        $this->assertStringContainsString('type(field, secrets.get(field.form))', $script);
        $this->assertStringNotContainsString('secret', (string) preg_replace('/\/\/.*$/m', '', implode("\n", $messages[0])));
    }
}
