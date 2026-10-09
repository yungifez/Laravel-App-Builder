<?php

namespace Tests\Unit;

use App\Features\NewTests;
use Tests\TestCase;

class NewTestsTest extends TestCase
{
    /**
     * Make the diff of one file: new, changed or deleted.
     */
    protected function diff(string $path, string $kind = 'changed'): string
    {
        return implode("\n", array_filter([
            "diff --git a/{$path} b/{$path}",
            match ($kind) {
                'new' => 'new file mode 100644',
                'deleted' => 'deleted file mode 100644',
                default => null,
            },
            $kind === 'new' ? '--- /dev/null' : "--- a/{$path}",
            $kind === 'deleted' ? '+++ /dev/null' : "+++ b/{$path}",
            '@@ -1 +1,2 @@',
            '+// changed',
        ]));
    }

    /**
     * A reported test, as the suite's report names it.
     *
     * @return array{file: string, name: string, outcome: string}
     */
    protected function ran(string $file, string $name, string $outcome = 'passed'): array
    {
        return ['file' => "/workspace/{$file}", 'name' => $name, 'outcome' => $outcome];
    }

    public function test_it_lists_the_test_files_the_changes_leave_and_whether_the_app_had_them()
    {
        $first = implode("\n", [
            $this->diff('tests/Feature/InviteTest.php', 'new'),
            $this->diff('tests/Feature/TeamTest.php'),
            $this->diff('tests/Feature/DraftTest.php', 'new'),
            // Not run by the suite: a helper and the app's code.
            $this->diff('tests/Support/Helper.php', 'new'),
            $this->diff('app/Models/Team.php'),
        ]);
        $second = implode("\n", [
            // A later change to a file an earlier one added: still new.
            $this->diff('tests/Feature/InviteTest.php'),
            $this->diff('tests/Feature/DraftTest.php', 'deleted'),
        ]);

        $this->assertSame(
            ['tests/Feature/InviteTest.php' => false, 'tests/Feature/TeamTest.php' => true],
            NewTests::files([$first, null, $second]),
        );
    }

    public function test_a_new_test_is_named_with_how_it_ended_without_the_change()
    {
        $files = ['tests/Feature/InviteTest.php' => false, 'tests/Feature/TeamTest.php' => true];
        $before = [
            $this->ran('tests/Feature/TeamTest.php', 'test_owners_rename_teams'),
        ];
        $without = [
            $this->ran('tests/Feature/InviteTest.php', 'test_owners_invite_members', 'failed'),
            $this->ran('tests/Feature/InviteTest.php', 'test_the_page_loads'),
            // A skipped test says nothing.
            $this->ran('tests/Feature/InviteTest.php', 'test_later', 'skipped'),
            // One data set that fails makes the test fail, and it is named once.
            $this->ran('tests/Feature/InviteTest.php', 'test_roles with data set "admin"'),
            $this->ran('tests/Feature/InviteTest.php', 'test_roles with data set "member"', 'failed'),
            // The app already had this one.
            $this->ran('tests/Feature/TeamTest.php', 'test_owners_rename_teams'),
            $this->ran('tests/Feature/TeamTest.php', 'test_members_see_invitations', 'failed'),
            // Another file's tests are not the change's.
            $this->ran('tests/Feature/BillingTest.php', 'test_seats_follow_members'),
        ];

        $this->assertSame([
            ['file' => 'tests/Feature/InviteTest.php', 'name' => 'test_owners_invite_members', 'without_change' => 'failed'],
            ['file' => 'tests/Feature/InviteTest.php', 'name' => 'test_the_page_loads', 'without_change' => 'passed'],
            ['file' => 'tests/Feature/InviteTest.php', 'name' => 'test_roles', 'without_change' => 'failed'],
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_members_see_invitations', 'without_change' => 'failed'],
        ], NewTests::found($files, $before, $without));
    }

    public function test_a_file_the_app_had_whose_old_tests_are_unknown_is_left_out()
    {
        // Its old tests cannot be told from its new ones.
        $this->assertSame([], NewTests::found(
            ['tests/Feature/TeamTest.php' => true],
            [],
            [$this->ran('tests/Feature/TeamTest.php', 'test_owners_rename_teams')],
        ));
    }

    public function test_the_new_tests_of_one_change_are_told_in_their_authors_words()
    {
        $tests = [
            ['file' => 'tests/Feature/InviteTest.php', 'name' => 'test_owners_invite_members', 'without_change' => 'failed'],
            ['file' => 'tests/Feature/InviteTest.php', 'name' => 'it shows the page', 'without_change' => 'passed'],
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_members_see_invitations', 'without_change' => 'failed'],
        ];
        $patch = $this->diff('tests/Feature/InviteTest.php', 'new');

        $this->assertSame(['Owners invite members'], NewTests::ending($tests, NewTests::FAILED, $patch));
        $this->assertSame(['It shows the page'], NewTests::ending($tests, NewTests::PASSED, $patch));
        $this->assertSame([], NewTests::ending($tests, NewTests::FAILED, null));
    }
}
