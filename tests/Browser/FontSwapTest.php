<?php

use App\Models\FeatureRequest;

/*
| Until a font arrives, text is set in a fallback sized to it, so the swap
| does not move text on a first visit (no-jitter rule). Each page is set
| in the fallbacks alone and compared with the loaded faces: no text may
| move up or down, and a word may only be a few pixels wider or narrower,
| as the faces match on average, not letter by letter.
*/

const FONT_SWAP_MOVES = <<<'JS'
(async () => {
    const faces = ['IBM Plex Sans', 'Schibsted Grotesk', 'IBM Plex Mono'];
    const all = [document.documentElement, document.body, ...document.body.querySelectorAll('*')];
    const stacks = all.map((el) => getComputedStyle(el).fontFamily);
    const inline = all.map((el) => el.style.fontFamily);
    const texts = all
        .filter((el) => [...el.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim()) && el.getClientRects().length)
        .filter((el) => { const box = el.getBoundingClientRect(); return box.top < innerHeight && box.bottom > 0; });
    // Each face is asked for: ready can come before one has begun to load.
    await Promise.all(texts.map((el) => { const style = getComputedStyle(el); return document.fonts.load([style.fontStyle, style.fontWeight, style.fontSize, style.fontFamily].join(' '), el.textContent); }));
    // Every element is given its stack, with the faces and then without.
    const setIn = (keep) => all.forEach((el, i) => { el.style.fontFamily = stacks[i].split(',').map((family) => family.trim()).filter((family) => keep || !faces.includes(family.replace(/["']/g, ''))).join(', '); });
    const boxes = () => texts.map((el) => { const box = el.getBoundingClientRect(); return { left: box.left, top: box.top, width: box.width, height: box.height }; });
    setIn(true);
    const loaded = boxes();
    setIn(false);
    const fallback = boxes();
    // The assertion may run this again, so the page is left as it was.
    all.forEach((el, i) => { el.style.fontFamily = inline[i]; });
    const most = (keys) => Math.max(0, ...loaded.map((box, i) => Math.max(...keys.map((key) => Math.abs(box[key] - fallback[i][key])))));
    return `${texts.length > 0} ${Math.round(most(['top', 'height']))} ${most(['left', 'width']) <= 8}`;
})()
JS;

dataset('font swap pages', [
    'home' => [fn () => '/'],
    'sign in' => [fn () => '/login'],
    'builder workspace' => [function () {
        // The same words each run, so a line never breaks by chance.
        fake()->seed(7);
        $change = FeatureRequest::factory()->create(['prompt' => 'Show a button to register when the feed is empty.']);
        test()->actingAs($change->user);

        return route('projects.show', $change->project);
    }],
]);

it('keeps text in place when the fonts swap in', function (Closure $url, int $width, int $height) {
    visit($url())
        ->resize($width, $height)
        ->assertScript(FONT_SWAP_MOVES, 'true 0 true')
        ->assertNoJavaScriptErrors();
})->with('font swap pages')->with([
    'phone' => [390, 844],
    'desktop' => [1440, 900],
]);
