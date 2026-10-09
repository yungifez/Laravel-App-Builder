<?php

/*
| A design change is checked in the rebuilt page, not assumed. A height that
| fills a row with no height of its own does nothing, so the change is put
| back and the owner is told why. The sample designer has the same panel and
| page script as an app, without an app to set up.
*/

/**
 * A script that waits up to 15 seconds for the app on show to meet the
 * condition, as each change rebuilds it.
 */
function shownWithin(string $condition): string
{
    return <<<JS
        (async () => {
            for (let tries = 0; tries < 60; tries++) {
                const app = document.querySelector('[data-test=preview-frame]')?.contentDocument;

                if (app && ({$condition})) {
                    return true;
                }

                await new Promise((resolve) => setTimeout(resolve, 250));
            }

            return false;
        })()
        JS;
}

/**
 * A script that waits for the panel to show the picked part, opens its size
 * choices, which wait behind "More choices", then makes the part fill the space in the row named.
 */
function fillOnceShown(string $row): string
{
    return shownWithin(<<<JS
        (() => {
            document.querySelector('[data-test=more-choices]')?.click();
            const size = [...document.querySelectorAll('h3 > button[aria-expanded=false]')].find((button) => button.textContent.trim() === 'Size');
            size?.click();
            const fill = document.querySelector('[role=group][aria-label={$row}] button[aria-label="Fill"]');
            fill?.click();
            return fill != null;
        })()
        JS);
}

it('puts back a change that did not show, and says so', function () {
    $page = visit(route('sample-design.show'));

    $page->assertSee('Cancel');

    $page->assertScript(fillOnceShown('Height'));

    $page->assertScript(shownWithin("document.body.innerText.includes(\"This change didn't show in your app, so I put it back.\")"))
        ->assertDontSee('Ask me to change it');

    $page->assertScript(shownWithin("app.querySelector('button')?.className.includes('h-full') === false"));
});

it('keeps a change that shows', function () {
    $page = visit(route('sample-design.show'));

    $page->assertSee('Cancel');

    $page->assertScript(fillOnceShown('Width'));

    $page->assertScript(shownWithin("app.querySelector('button')?.className.includes('w-full') === true"));

    $page->assertDontSee("This change didn't show");
});
