<?php

namespace Tests\Unit;

use App\Features\NarrowedFormats;
use Tests\TestCase;

/**
 * A change that makes a field's format stricter is found from the rules
 * it edits, and the owner is asked with the count in plain words (§9).
 */
class NarrowedFormatsTest extends TestCase
{
    public function test_a_rule_made_stricter_is_found_with_what_it_accepted_and_accepts()
    {
        $patch = $this->editing('app/Http/Requests/StoreBranchRequest.php', [
            ["'phone' => ['required', 'string', 'phone:INTERNATIONAL'],", "'phone' => ['required', 'string', 'phone:CA'],"],
            ["'postcode' => ['required', 'string', new ValidPostalCode(['CA', 'US'])],", "'postcode' => ['required', 'string', new ValidPostalCode(['CA'])],"],
            ["'book' => ['required', 'string', new ValidIsbn([10, 13])],", "'book' => ['required', 'string', new ValidIsbn([13])],"],
            ["'site' => ['required', 'string', 'max:2048', 'url:http,https'],", "'site' => ['required', 'string', 'max:2048', 'url:https'],"],
        ]);

        $this->assertSame([
            ['path' => 'app/Http/Requests/StoreBranchRequest.php', 'table' => 'branches', 'column' => 'phone', 'kind' => 'phone', 'before' => ['any'], 'after' => ['CA']],
            ['path' => 'app/Http/Requests/StoreBranchRequest.php', 'table' => 'branches', 'column' => 'postcode', 'kind' => 'postal_code', 'before' => ['CA', 'US'], 'after' => ['CA']],
            ['path' => 'app/Http/Requests/StoreBranchRequest.php', 'table' => 'branches', 'column' => 'book', 'kind' => 'isbn', 'before' => ['10', '13'], 'after' => ['13']],
            ['path' => 'app/Http/Requests/StoreBranchRequest.php', 'table' => 'branches', 'column' => 'site', 'kind' => 'url', 'before' => ['http', 'https'], 'after' => ['https']],
        ], NarrowedFormats::inPatch($patch));
    }

    public function test_a_wider_or_new_rule_and_rules_outside_form_requests_are_not_a_narrowing()
    {
        $patch = implode("\n", [
            $this->editing('app/Http/Requests/UpdateBranchRequest.php', [
                // Widening is safe.
                ["'phone' => ['required', 'string', 'phone:CA'],", "'phone' => ['required', 'string', 'phone:INTERNATIONAL'],"],
                ["'postcode' => ['required', 'string', new ValidPostalCode(['CA'])],", "'postcode' => ['required', 'string', new ValidPostalCode(['CA', 'US'])],"],
                // The same rule, and a different kind of rule.
                ["'book' => ['required', 'string', new ValidIsbn([13])],", "'book' => ['nullable', 'string', new ValidIsbn([13])],"],
                ["'title' => ['required', 'string', 'max:255'],", "'title' => ['required', 'string', 'max:100'],"],
            ]),
            // A new field has nothing saved under an older rule.
            $this->editing('app/Http/Requests/StoreBranchRequest.php', [[null, "'fax' => ['nullable', 'string', 'phone:CA'],"]]),
            $this->editing('app/Support/Rules.php', [["'phone' => 'phone:INTERNATIONAL',", "'phone' => 'phone:CA',"]]),
        ]);

        $this->assertSame([], NarrowedFormats::inPatch($patch));
        $this->assertSame([], NarrowedFormats::inPatch(null));
    }

    public function test_the_owner_is_asked_only_when_saved_rows_fail_and_until_they_keep_it()
    {
        $checked = [
            ['table' => 'branches', 'column' => 'phone', 'kind' => 'phone', 'after' => ['CA'], 'rows' => 30, 'failing' => 12],
            ['table' => 'branches', 'column' => 'book', 'kind' => 'isbn', 'after' => ['13'], 'rows' => 4, 'failing' => 1],
            ['table' => 'branches', 'column' => 'postcode', 'kind' => 'postal_code', 'after' => ['CA', 'US'], 'rows' => 9, 'failing' => 0],
            ['table' => 'branches', 'column' => 'site', 'kind' => 'url', 'after' => ['https'], 'rows' => null, 'failing' => null],
        ];

        $findings = NarrowedFormats::findings($checked);

        $this->assertSame(['format_narrowed|branches.phone', 'format_narrowed|branches.book'], array_map(NarrowedFormats::identity(...), $findings));
        $this->assertSame('12 saved phone numbers are not from Canada, and the stricter rule would turn them away from now on. I kept the old rule. If you want them turned away, say so.', NarrowedFormats::question($findings[0]));
        $this->assertSame('1 saved ISBN is not a 13-digit ISBN, and the stricter rule would turn them away from now on. I kept the old rule. If you want them turned away, say so.', NarrowedFormats::question($findings[1]));
        $this->assertStringContainsString('branches.phone: the change makes the phone rule stricter, and 12 saved rows fail it.', NarrowedFormats::finding($findings[0]));
        $this->assertSame('9 saved postal codes are not from Canada or United States, and the stricter rule would turn them away from now on. I kept the old rule. If you want them turned away, say so.', NarrowedFormats::question([...$findings[0], 'things' => 'postal_code', 'failing' => 9, 'after' => ['CA', 'US']]));

        $this->assertSame('You chose to turn away phone numbers that are not from Canada from now on. Saved ones stay as they are.', NarrowedFormats::chosen($findings[0]));

        // Once the owner keeps it, it no longer holds the change.
        $this->assertSame(['format_narrowed|branches.book'], array_map(NarrowedFormats::identity(...), NarrowedFormats::findings($checked, ['format_narrowed|branches.phone'])));
    }

    public function test_a_narrowing_that_could_not_be_counted_says_why()
    {
        $this->assertSame('Your app was not running, so saved phone numbers were not checked against the stricter rule.', NarrowedFormats::unchecked(['kind' => 'phone'], running: false));
        $this->assertSame('There are no saved ISBNs to check.', NarrowedFormats::unchecked(['kind' => 'isbn', 'rows' => 0], running: true));
        $this->assertSame('Saved web addresses could not be read, so they were not checked against the stricter rule.', NarrowedFormats::unchecked(['kind' => 'url', 'rows' => null], running: true));
    }

    /**
     * Make a patch that changes each rule line from one form to another,
     * or adds it when there was none.
     *
     * @param  list<array{0: string|null, 1: string}>  $changes
     */
    protected function editing(string $path, array $changes): string
    {
        $lines = [];

        foreach ($changes as [$before, $after]) {
            if ($before !== null) {
                $lines[] = "-            {$before}";
            }

            $lines[] = "+            {$after}";
        }

        return implode("\n", ["diff --git a/{$path} b/{$path}", "--- a/{$path}", "+++ b/{$path}", '@@ -1,4 +1,4 @@', ' <?php', ...$lines]);
    }
}
