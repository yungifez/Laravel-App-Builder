<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\TemplateElement;
use PHPUnit\Framework\TestCase;

class TemplateElementTest extends TestCase
{
    protected const TEMPLATE = <<<'VUE'
        <template>
            <div class="flex gap-4" data-x='a > b'>
                <Button
                    variant="outline"
                    class="w-full"
                    @click="save"
                >Save</Button>
                <span :class="{ 'opacity-50': busy }">Busy</span>
                <p :class="cn('text-sm p-2', props.class)">Hi</p>
                <hr />
            </div>
        </template>
        VUE;

    public function test_it_finds_a_static_class_at_the_line_and_column()
    {
        $element = TemplateElement::at(self::TEMPLATE, 2, 5);

        $this->assertSame('div', $element->tag);
        $this->assertSame('flex gap-4', $element->classes['value']);
        $this->assertTrue($element->editable());
        $this->assertStringContainsString('<div class="flex gap-6" data-x=\'a > b\'>', $element->withClasses(self::TEMPLATE, 'flex gap-6'));
    }

    public function test_it_reads_attributes_across_lines_on_a_component()
    {
        $element = TemplateElement::at(self::TEMPLATE, 3, 9);

        $this->assertSame('Button', $element->tag);
        $this->assertSame('w-full', $element->classes['value']);
        $this->assertSame('@click="save"'."\n        >", substr(self::TEMPLATE, $element->end - 23, 23));
    }

    public function test_a_class_binding_is_editable_only_through_a_literal_cn_string()
    {
        $bound = TemplateElement::at(self::TEMPLATE, 8, 9);
        $helper = TemplateElement::at(self::TEMPLATE, 9, 9);

        $this->assertFalse($bound->editable());
        $this->assertNull($bound->classes);
        $this->assertTrue($helper->editable());
        $this->assertSame('text-sm p-2', $helper->classes['value']);
        $this->assertStringContainsString("cn('text-sm p-4', props.class)", $helper->withClasses(self::TEMPLATE, 'text-sm p-4'));
    }

    public function test_an_element_without_classes_gets_a_class_attribute()
    {
        $element = TemplateElement::at(self::TEMPLATE, 10, 9);

        $this->assertTrue($element->editable());
        $this->assertStringContainsString('<hr class="my-4" />', $element->withClasses(self::TEMPLATE, 'my-4'));
    }

    public function test_nothing_is_found_where_no_tag_starts()
    {
        $this->assertNull(TemplateElement::at(self::TEMPLATE, 2, 6));
        $this->assertNull(TemplateElement::at(self::TEMPLATE, 99, 1));
    }
}
