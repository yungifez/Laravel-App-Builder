<?php

namespace Tests\Feature\Features;

use App\Ai\Agents\FeaturePlanner;
use App\Features\WrittenTests;
use App\Runs\Exceptions\ConstructionFailed;
use Tests\TestCase;

class WrittenTestsTest extends TestCase
{
    protected const PEST = "<?php\n\nuse App\\Models\\Team;\n\nit('lets an owner describe a team', function () {\n    expect(true)->toBeTrue();\n});\n\ntest(\"a member cannot describe a team\", function () {\n    expect(true)->toBeTrue();\n});\n";

    protected const PHPUNIT = "<?php\n\nnamespace Tests\\Feature;\n\nuse PHPUnit\\Framework\\Attributes\\Test;\nuse Tests\\TestCase;\n\nclass TeamTotalTest extends TestCase\n{\n    public function test_the_total_is_shown_in_pounds(): void\n    {\n        \$this->assertTrue(true);\n    }\n\n    #[Test]\n    public function a_free_team_shows_no_total(): void\n    {\n        \$this->assertTrue(true);\n    }\n}\n";

    public function test_new_files_that_parse_with_one_named_test_per_item_are_kept()
    {
        $written = WrittenTests::check([
            'files' => [
                ['path' => './tests/Feature/TeamDescriptionTest.php', 'contents' => self::PEST],
                ['path' => 'tests/Feature/TeamTotalTest.php', 'contents' => self::PHPUNIT],
            ],
            'tests' => [
                ['item' => 2, 'file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'a member cannot describe a team'],
                ['item' => 1, 'file' => 'tests/Feature/TeamDescriptionTest.php', 'name' => 'it lets an owner describe a team'],
                ['item' => 3, 'file' => 'tests/Feature/TeamTotalTest.php', 'name' => 'test_the_total_is_shown_in_pounds'],
                ['item' => 4, 'file' => 'tests/Feature/TeamTotalTest.php', 'name' => 'a_free_team_shows_no_total'],
            ],
        ], ['base', 'alternate', 'base', 'alternate'], fn () => false);

        $this->assertSame(['tests/Feature/TeamDescriptionTest.php', 'tests/Feature/TeamTotalTest.php'], array_keys($written['files']));
        $this->assertSame([1, 2, 3, 4], array_column($written['tests'], 'item'));
        $this->assertSame('it lets an owner describe a team', $written['tests'][0]['name']);
    }

    public function test_output_that_breaks_a_rule_is_refused_with_every_reason()
    {
        $refused = function (array $output, array $kinds = ['base', 'alternate'], ?callable $exists = null): string {
            try {
                WrittenTests::check($output, $kinds, $exists ?? fn () => false);
            } catch (ConstructionFailed $exception) {
                return $exception->getMessage();
            }

            $this->fail('The output was kept.');
        };
        $file = ['path' => 'tests/Feature/TeamDescriptionTest.php', 'contents' => self::PEST];
        $both = [
            ['item' => 1, 'file' => $file['path'], 'name' => 'lets an owner describe a team'],
            ['item' => 2, 'file' => $file['path'], 'name' => 'a member cannot describe a team'],
        ];

        // Where the suite does not run it, over an existing file, or not PHP.
        $this->assertStringContainsString('not where the test suite runs it', $refused(['files' => [['path' => 'app/TeamTest.php', 'contents' => self::PEST]], 'tests' => []]));
        $this->assertStringContainsString('not where the test suite runs it', $refused(['files' => [['path' => 'tests/Acceptance/TeamTest.php', 'contents' => self::PEST]], 'tests' => []]));
        $this->assertStringContainsString('already exists', $refused(['files' => [$file], 'tests' => $both], exists: fn (string $path) => $path === $file['path']));
        $this->assertStringContainsString('not valid PHP', $refused(['files' => [['path' => $file['path'], 'contents' => "<?php\n\nit('x', function () {\n"]], 'tests' => []]));

        // An item without a test, a test the file does not hold, one test for two items.
        $this->assertStringContainsString('Item 2 has no test.', $refused(['files' => [$file], 'tests' => [$both[0]]]));
        $this->assertStringContainsString('has no test named "members are turned away"', $refused(['files' => [$file], 'tests' => [$both[0], [...$both[1], 'name' => 'members are turned away']]]));
        $this->assertStringContainsString('given for more than one item', $refused(['files' => [$file], 'tests' => [$both[0], [...$both[0], 'item' => 2]]]));
        $this->assertStringContainsString('There is no item 3.', $refused(['files' => [$file], 'tests' => [...$both, [...$both[0], 'item' => 3]]]));
        $this->assertStringContainsString('No test files were written.', $refused(['files' => [], 'tests' => []]));
    }

    public function test_an_exception_test_that_asserts_the_app_refuses_is_kept()
    {
        $pest = "<?php\n\nit('shows a team', function () {\n    \$this->get('/teams/1')->assertOk();\n});\n\ntest('a member cannot rename a team', function () {\n    \$this->patch('/teams/1', ['name' => 'X'])->assertForbidden();\n});\n\nit('refuses an empty name', function () {\n    \$this->post('/teams', [])->assertInvalid(['name']);\n});\n\nit('sends guests to sign in', function () {\n    \$this->get('/teams')->assertRedirect(route('login'));\n});\n\nit('refuses a missing team', function () {\n    \$this->get('/teams/99')->assertStatus(404);\n});\n\nit('stops on a bad plan', function () {\n    app(Plans::class)->pick('none');\n})->throws(InvalidArgumentException::class);\n";
        $phpunit = "<?php\n\nclass TeamLimitTest extends TestCase\n{\n    public function test_a_full_team_takes_no_one_more(): void\n    {\n        \$this->expectException(TeamFull::class);\n        \$this->team->add(\$this->user);\n    }\n\n    public function test_the_import_command_fails_on_a_bad_file(): void\n    {\n        \$this->artisan('teams:import bad.csv')->assertFailed();\n    }\n}\n";

        $written = WrittenTests::check([
            'files' => [
                ['path' => 'tests/Feature/TeamRulesTest.php', 'contents' => $pest],
                ['path' => 'tests/Feature/TeamLimitTest.php', 'contents' => $phpunit],
            ],
            'tests' => [
                ['item' => 1, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'it shows a team'],
                ['item' => 2, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'a member cannot rename a team'],
                ['item' => 3, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'it refuses an empty name'],
                ['item' => 4, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'it sends guests to sign in'],
                ['item' => 5, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'it refuses a missing team'],
                ['item' => 6, 'file' => 'tests/Feature/TeamRulesTest.php', 'name' => 'it stops on a bad plan'],
                ['item' => 7, 'file' => 'tests/Feature/TeamLimitTest.php', 'name' => 'test_a_full_team_takes_no_one_more'],
                ['item' => 8, 'file' => 'tests/Feature/TeamLimitTest.php', 'name' => 'test_the_import_command_fails_on_a_bad_file'],
            ],
        ], ['base', 'exception', 'exception', 'exception', 'exception', 'exception', 'exception', 'exception'], fn () => false);

        $this->assertCount(8, $written['tests']);
    }

    public function test_a_redirect_to_confirm_an_email_or_password_is_a_refusal()
    {
        $this->assertTrue(WrittenTests::assertsRefusal("\$this->actingAs(\$unconfirmed)->delete('/items/1')->assertRedirect(route('verification.notice'));"));
        $this->assertTrue(WrittenTests::assertsRefusal("\$this->actingAs(\$user)->delete('/account')->assertRedirect('/user/confirm-password');"));

        // A redirect anywhere else may be the app working as usual.
        $this->assertFalse(WrittenTests::assertsRefusal("\$this->actingAs(\$user)->delete('/items/1')->assertRedirect(route('dashboard'));"));
    }

    public function test_a_record_still_there_after_a_delete_is_a_refusal()
    {
        $this->assertTrue(WrittenTests::assertsRefusal("\$this->actingAs(\$user)->delete(route('books.destroy', \$book))->assertRedirect();\n\$this->assertModelExists(\$book);"));
        $this->assertTrue(WrittenTests::assertsRefusal("\$this->deleteJson('/api/members/1');\n\$this->assertDatabaseHas('members', ['id' => 1]);"));

        // Still there without trying to delete it, or gone after it, is no refusal.
        $this->assertFalse(WrittenTests::assertsRefusal("\$this->get('/books')->assertOk();\n\$this->assertModelExists(\$book);"));
        $this->assertFalse(WrittenTests::assertsRefusal("\$this->delete(route('books.destroy', \$book));\n\$this->assertModelMissing(\$book);"));
    }

    public function test_an_exception_test_that_expects_success_is_refused_with_the_reason()
    {
        // The refusal is asserted only by the test after it, which does
        // not count for this one.
        $pest = "<?php\n\nit('shows no times for a past day', function () {\n    \$this->get('/?day=2020-01-01')->assertOk()->assertSee('No times');\n});\n\nit('refuses a past day', function () {\n    \$this->post('/bookings', ['day' => '2020-01-01'])->assertInvalid(['day']);\n});\n";

        try {
            WrittenTests::check([
                'files' => [['path' => 'tests/Feature/BookingTest.php', 'contents' => $pest]],
                'tests' => [
                    ['item' => 1, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'it shows no times for a past day'],
                    ['item' => 2, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'it refuses a past day'],
                ],
            ], ['exception', 'exception'], fn () => false);
        } catch (ConstructionFailed $exception) {
            $this->assertSame('The test "it shows no times for a past day" for item 1 is an exception case, but it asserts no refusal. Assert that the app refuses: a 403 or 404, validation errors, a redirect to sign in or to confirm an email or password, a thrown exception or failed command, or a record still there after it was deleted.', $exception->getMessage());

            return;
        }

        $this->fail('The test that expects success was kept.');
    }

    public function test_a_graceful_answer_is_planned_as_an_alternate_and_its_test_expects_success()
    {
        // The planner is told an answer that shows something else is not a
        // refusal, so the case comes as an alternate.
        $this->assertStringContainsString('sees no times", "An owner who gives a date that does not exist sees today\'s bookings"), that is an alternate, not an exception', (string) (new FeaturePlanner)->instructions());

        $pest = "<?php\n\nit('shows no times for a past day', function () {\n    \$this->get('/?day=2020-01-01')->assertOk()->assertSee('No times');\n});\n\nit('refuses a past day', function () {\n    \$this->post('/bookings', ['day' => '2020-01-01'])->assertInvalid(['day']);\n});\n";

        $written = WrittenTests::check([
            'files' => [['path' => 'tests/Feature/BookingTest.php', 'contents' => $pest]],
            'tests' => [
                ['item' => 1, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'it shows no times for a past day'],
                ['item' => 2, 'file' => 'tests/Feature/BookingTest.php', 'name' => 'it refuses a past day'],
            ],
        ], ['alternate', 'exception'], fn () => false);

        $this->assertSame([1, 2], array_column($written['tests'], 'item'));
    }
}
