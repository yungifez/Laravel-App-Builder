<?php

namespace Tests\Unit;

use App\Features\TestMap;
use Tests\TestCase;

class TestNameTest extends TestCase
{
    public function test_a_method_name_reads_as_a_sentence()
    {
        $this->assertSame('Owners rename teams', TestMap::describe('test_owners_rename_teams'));
        $this->assertSame('Owners rename teams', TestMap::describe('testOwnersRenameTeams'));
        $this->assertSame('Seats follow members', TestMap::describe('test_seats_follow_members with data set "one"'));
    }

    public function test_a_pest_description_keeps_its_capitals()
    {
        $this->assertSame('It links the Terms of Service line to the Terms of Service page', TestMap::describe('it links the Terms of Service line to the Terms of Service page'));
        $this->assertSame('It tells a visitor that Pinkary is free', TestMap::describe('__pest_evaluable_it_tells_a_visitor_that_Pinkary_is_free'));
        $this->assertSame('It orders', TestMap::describe('it orders with data set "(\'a\')"'));
    }
}
