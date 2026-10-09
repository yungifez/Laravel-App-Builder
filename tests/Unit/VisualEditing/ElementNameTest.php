<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\ElementName;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ElementNameTest extends TestCase
{
    #[TestWith(['h2', 'a heading'])]
    #[TestWith(['button', 'a button'])]
    #[TestWith(['div', 'a box'])]
    #[TestWith(['img', 'a picture'])]
    #[TestWith(['Label', 'a label'])]
    #[TestWith(['CardTitle', 'a card title'])]
    #[TestWith(['AppLogoIcon', 'an app logo icon'])]
    #[TestWith(['router-link', 'a router link'])]
    #[TestWith(['x-forms.input', 'a forms input'])]
    #[TestWith(['livewire:student-list', 'a student list'])]
    #[TestWith(['_', 'a box'])]
    public function test_a_tag_reads_as_words(string $tag, string $words)
    {
        $this->assertSame($words, ElementName::for($tag));
    }
}
