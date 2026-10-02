<?php

namespace Tests\Unit\Scaffolding;

use App\Scaffolding\ShapeWording;
use Tests\TestCase;

class ShapeWordingTest extends TestCase
{
    public function test_the_owner_reads_what_is_kept_in_their_words()
    {
        $sentences = (new ShapeWording)->describe([[
            'name' => 'Booking',
            'label' => 'booking',
            'fields' => [
                ['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User', 'label' => 'who booked'],
                ['name' => 'starts_at', 'type' => 'datetime', 'required' => true, 'choices' => [], 'of' => null, 'label' => 'when it starts'],
                ['name' => 'status', 'type' => 'choice', 'required' => true, 'choices' => ['pending', 'confirmed', 'not_coming'], 'of' => null, 'label' => 'whether it is confirmed'],
                ['name' => 'notes', 'type' => 'text', 'required' => false, 'choices' => [], 'of' => null, 'label' => 'a note'],
            ],
            'access' => ['view' => 'creator', 'create' => 'signed_in', 'update' => 'creator', 'delete' => 'creator'],
        ]]);

        $this->assertSame([
            'For each booking I keep: who booked, when it starts, whether it is confirmed (pending, confirmed or not coming) and a note if there is one.'
            .' Anyone signed in can add bookings. Only the person who added a booking can see, change or remove it.',
        ], $sentences);
    }

    public function test_without_labels_the_words_come_from_the_names_never_the_code()
    {
        $sentences = (new ShapeWording)->describe([[
            'name' => 'MeetingRoom',
            'fields' => [
                ['name' => 'user', 'type' => 'belongs_to', 'required' => true, 'choices' => [], 'of' => 'User'],
                ['name' => 'opens_on', 'type' => 'date', 'required' => false, 'choices' => [], 'of' => null],
                ['name' => 'is_public', 'type' => 'boolean', 'required' => false, 'choices' => [], 'of' => null],
            ],
            'access' => ['view' => 'everyone', 'create' => 'creator', 'update' => 'signed_in', 'delete' => 'signed_in'],
        ]]);

        $this->assertSame([
            'For each meeting room I keep: who added it, the opens if there is one and whether it is public.'
            .' Anyone can see meeting rooms. Anyone signed in can add, change or remove meeting rooms.',
        ], $sentences);
    }

    public function test_a_record_without_access_says_only_what_it_keeps()
    {
        $this->assertSame(['For each note I keep: the body.'], (new ShapeWording)->describe([[
            'name' => 'Note',
            'fields' => [['name' => 'body', 'type' => 'text', 'required' => true, 'choices' => [], 'of' => null]],
            'access' => null,
        ]]));
    }
}
