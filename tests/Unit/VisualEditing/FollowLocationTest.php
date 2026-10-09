<?php

namespace Tests\Unit\VisualEditing;

use App\Actions\VisualEditing\FollowLocation;
use PHPUnit\Framework\TestCase;

class FollowLocationTest extends TestCase
{
    public function test_lines_outside_the_changes_shift_by_the_lines_added_and_removed_before_them()
    {
        // Two lines added after line 3, and line 10 removed.
        $hunks = [[3, 0, 4, 2], [10, 1, 11, 0]];

        $this->assertSame(2, FollowLocation::line($hunks, 2));
        $this->assertSame(3, FollowLocation::line($hunks, 3));
        $this->assertSame(6, FollowLocation::line($hunks, 4));
        $this->assertSame(11, FollowLocation::line($hunks, 9));
        $this->assertNull(FollowLocation::line($hunks, 10));
        $this->assertSame(12, FollowLocation::line($hunks, 11));
    }

    public function test_a_line_rewritten_in_place_keeps_its_place()
    {
        $this->assertSame(5, FollowLocation::line([[5, 1, 5, 1]], 5));
        $this->assertSame(8, FollowLocation::line([[1, 0, 2, 1], [6, 2, 7, 2]], 7));
    }

    public function test_a_line_inside_a_change_that_added_or_removed_lines_cannot_be_followed()
    {
        $this->assertNull(FollowLocation::line([[5, 2, 5, 3]], 6));
    }
}
