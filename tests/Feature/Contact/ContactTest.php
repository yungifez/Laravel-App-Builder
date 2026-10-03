<?php

namespace Tests\Feature\Contact;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Honeypot\Honeypot;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com'], 'honeypot.amount_of_seconds' => 0]);
        Notification::fake();
    }

    public function test_the_form_is_filled_in_for_someone_signed_in()
    {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $this->actingAs($user)
            ->get(route('contact'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Contact')
                ->where('name', 'Ada')
                ->where('email', 'ada@example.com')
                ->where('honeypot.enabled', true));
    }

    public function test_a_message_is_kept_and_emailed_to_the_operators()
    {
        $this->post(route('contact.store'), $this->fields(['message' => 'How do I move my app to my own server?']))
            ->assertRedirect(route('contact'));

        $message = ContactMessage::query()->sole();
        $this->assertSame('How do I move my app to my own server?', $message->message);
        $this->assertNull($message->user_id);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            ContactMessageReceived::class,
            fn (ContactMessageReceived $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['ops@example.com']
                && $notification->toMail($notifiable)->replyTo === [['grace@example.com', 'Grace']],
        );
    }

    public function test_a_form_filled_in_by_a_program_is_dropped()
    {
        $honeypot = app(Honeypot::class);

        $this->post(route('contact.store'), [...$this->fields(), $honeypot->unrandomizedNameFieldName() => 'bot'])->assertOk();
        $this->post(route('contact.store'), ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy cheap things today!'])->assertOk();

        $this->assertSame(0, ContactMessage::query()->count());
        Notification::assertNothingSent();
    }

    public function test_an_empty_or_short_message_is_refused()
    {
        $this->post(route('contact.store'), $this->fields(['message' => 'hi', 'email' => 'not an email']))
            ->assertSessionHasErrors(['message', 'email']);

        $this->assertSame(0, ContactMessage::query()->count());
    }

    /**
     * Build the fields the form sends, with the spam check passing.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function fields(array $overrides = []): array
    {
        $honeypot = app(Honeypot::class);

        return [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'message' => 'Hello, I have a question about plans.',
            $honeypot->unrandomizedNameFieldName() => '',
            $honeypot->validFromFieldName() => $honeypot->encryptedValidFrom(),
            ...$overrides,
        ];
    }
}
