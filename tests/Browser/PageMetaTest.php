<?php

/*
| What a public page says in search results and link previews. Each page
| replaces the default description instead of adding a second one.
*/

it('gives a public page its own description and share preview', function () {
    visit('/pricing')
        ->assertScript("document.querySelectorAll('meta[name=description]').length", 1)
        ->assertScript(
            "document.querySelector('meta[name=description]').content",
            'Every plan builds the same way and checks each change before you see it. Bigger plans only include more use each month.',
        )
        ->assertScript("document.querySelector('meta[property=\"og:title\"]').content", 'Pricing');
});

it('keeps the default description on a page without its own', function () {
    visit('/login')
        ->assertScript(
            "document.querySelector('meta[name=description]').content",
            "Build apps that don't stay prototypes. Every change passes fixed checks before you keep it.",
        );
});
