<?php

namespace Tests\Feature\Features;

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
        ], 4, fn () => false);

        $this->assertSame(['tests/Feature/TeamDescriptionTest.php', 'tests/Feature/TeamTotalTest.php'], array_keys($written['files']));
        $this->assertSame([1, 2, 3, 4], array_column($written['tests'], 'item'));
        $this->assertSame('it lets an owner describe a team', $written['tests'][0]['name']);
    }

    public function test_output_that_breaks_a_rule_is_refused_with_every_reason()
    {
        $refused = function (array $output, int $items = 2, ?callable $exists = null): string {
            try {
                WrittenTests::check($output, $items, $exists ?? fn () => false);
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
}
