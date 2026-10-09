<?php

namespace Tests\Unit\Context;

use App\Context\NotesDocument;
use PHPUnit\Framework\TestCase;

class NotesDocumentTest extends TestCase
{
    protected const NOTES = <<<'MARKDOWN'
    ---
    capability: plans
    summary: Customers pick a plan.
    ---

    # Plans

    Customers pick one of three plans.

    ## Rules

    - Every customer sees the same plans.
    - Prices include tax,
      shown in the customer's currency.

    ## Notes

    Plans change once a year.

    MARKDOWN;

    public function test_it_reads_the_title_introduction_sections_and_list_items()
    {
        $notes = NotesDocument::parse(self::NOTES);

        $this->assertSame('Plans', $notes->title);
        $this->assertSame('Customers pick one of three plans.', $notes->introduction);
        $this->assertSame('Plans change once a year.', $notes->section('notes'));
        $this->assertNull($notes->section('Engineering direction'));
        $this->assertSame(['Every customer sees the same plans.', "Prices include tax, shown in the customer's currency."], $notes->items('Rules'));
    }

    public function test_editing_one_section_keeps_the_others_and_the_frontmatter()
    {
        $edited = NotesDocument::parse(self::NOTES)->withSection('Rules', "- Only owners change prices.\n")->toMarkdown();

        $this->assertStringStartsWith("---\ncapability: plans\nsummary: Customers pick a plan.\n---\n\n# Plans\n\nCustomers pick one of three plans.\n\n## Rules\n\n- Only owners change prices.\n\n## Notes", $edited);
        $this->assertSame(NotesDocument::parse(self::NOTES)->toMarkdown(), NotesDocument::parse(NotesDocument::parse(self::NOTES)->toMarkdown())->toMarkdown());
    }

    public function test_a_new_section_is_added_at_the_end_and_empty_text_removes_one()
    {
        $notes = NotesDocument::parse(self::NOTES)
            ->withSection('Engineering direction', 'Use Actions for changes.')
            ->withSection('Notes', '');

        $this->assertSame(['Rules', 'Engineering direction'], array_column($notes->sections, 'heading'));
        $this->assertSame('New intro.', $notes->withIntroduction("New intro.\n")->introduction);
    }

    public function test_the_summary_is_rewritten_without_touching_the_other_fields()
    {
        $notes = NotesDocument::parse(self::NOTES);

        $this->assertStringStartsWith("---\ncapability: plans\nsummary: 'Plans: pick one.'\n---\n", $notes->withSummary("Plans:\npick one.")->toMarkdown());
        $this->assertStringStartsWith("---\ncapability: plans\n---\n", $notes->withSummary('')->toMarkdown());
        $this->assertSame("---\nsummary: Signing in.\n---\n", NotesDocument::parse("# Account\n")->withSummary('Signing in.')->frontmatter);

        $multiline = NotesDocument::parse("---\ncapability: plans\nsummary: >\n    Long\n    text.\npaths:\n    - app/*\n---\n# Plans\n");
        $this->assertSame("---\ncapability: plans\nsummary: Short.\npaths:\n    - app/*\n---\n", $multiline->withSummary('Short.')->frontmatter);
    }
}
