<?php

namespace Tests\Unit;

use App\Features\OwnFormatChecks;
use Tests\TestCase;

/**
 * A change that writes its own check for a formatted field is sent back
 * (§9 Formats): the generated rule decides what the field accepts.
 */
class OwnFormatChecksTest extends TestCase
{
    public function test_a_regex_rule_or_preg_match_on_a_formatted_field_is_found()
    {
        $patch = implode("\n", [
            $this->adding('app/Http/Requests/StoreBranchRequest.php', [
                "            'title' => ['required', 'string'],",
                "            'phone' => ['required', 'regex:/^\\+?[0-9 ]+$/'],",
            ]),
            $this->adding('app/Http/Controllers/BranchController.php', [
                "        if (! preg_match('/^[A-Z]\\d[A-Z] ?\\d[A-Z]\\d$/', \$request->postcode)) {",
            ]),
        ]);

        $found = OwnFormatChecks::found($patch, $this->records());

        $this->assertSame([
            ['path' => 'app/Http/Requests/StoreBranchRequest.php', 'line' => 4, 'field' => 'phone', 'type' => 'phone'],
            ['path' => 'app/Http/Controllers/BranchController.php', 'line' => 3, 'field' => 'postcode', 'type' => 'postal_code'],
        ], $found);
        $this->assertSame(
            'Line 3 of app/Http/Controllers/BranchController.php writes its own check for postcode, a postal code field. The generated rule and cast already decide what it accepts and how it is stored, and a second check can disagree with them. Remove this check and use the generated rule.',
            OwnFormatChecks::finding($found[1]),
        );
    }

    public function test_the_apps_own_codes_its_tests_and_other_fields_may_use_a_pattern()
    {
        $patch = implode("\n", [
            $this->adding('app/Http/Requests/StoreBranchRequest.php', [
                // A pattern field is checked by its regex on purpose.
                "            'code' => ['required', 'regex:/^BR-\\d{3}$/'],",
                // A field with no format may be checked any way.
                "            'title' => ['required', 'regex:/^[A-Z]/'],",
                // The generated rule, not a check of its own.
                "            'phone' => ['required', 'string', 'phone:CA'],",
            ]),
            $this->adding('tests/Feature/BranchTest.php', ["        \$this->assertSame(1, preg_match('/^\\+1/', \$branch->phone));"]),
        ]);

        $this->assertSame([], OwnFormatChecks::found($patch, $this->records()));
    }

    public function test_nothing_is_found_without_formatted_fields_or_on_lines_the_app_had()
    {
        $old = implode("\n", [
            'diff --git a/app/Support/Phone.php b/app/Support/Phone.php',
            '--- a/app/Support/Phone.php',
            '+++ b/app/Support/Phone.php',
            '@@ -1,2 +1,2 @@',
            " return preg_match('/^\\+/', \$phone) === 1;",
            '-// old',
            '+// new',
        ]);
        $added = $this->adding('app/Http/Requests/StoreBranchRequest.php', ["            'phone' => ['required', 'regex:/^\\+/'],"]);

        $this->assertSame([], OwnFormatChecks::found($old, $this->records()));
        $this->assertSame([], OwnFormatChecks::found($added, [['name' => 'Branch', 'fields' => [['name' => 'phone', 'type' => 'string', 'required' => true, 'choices' => [], 'of' => null]], 'access' => null]]));
        $this->assertSame([], OwnFormatChecks::found(null, $this->records()));
        $this->assertSame([], OwnFormatChecks::found($added, []));
    }

    /**
     * @return list<array{name: string, fields: list<array{name: string, type: string, required: bool, choices: list<string>, of: string|null}>, access: null}>
     */
    protected function records(): array
    {
        $field = fn (string $name, string $type) => ['name' => $name, 'type' => $type, 'required' => true, 'choices' => [], 'of' => null];

        return [['name' => 'Branch', 'fields' => [$field('title', 'string'), $field('phone', 'phone'), $field('postcode', 'postal_code'), $field('code', 'pattern')], 'access' => null]];
    }

    /**
     * Make a patch that adds the given lines to a file after two lines it
     * already had.
     *
     * @param  list<string>  $lines
     */
    protected function adding(string $path, array $lines): string
    {
        return implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1,2 +1,'.(2 + count($lines)).' @@',
            ' first',
            ' second',
            ...array_map(fn (string $line) => '+'.$line, $lines),
        ]);
    }
}
