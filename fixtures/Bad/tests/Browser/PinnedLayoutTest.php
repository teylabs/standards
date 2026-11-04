<?php

// A pinned layout assertion: no-pixel-pins flags the padding pinned to 8px.
it('pins a row padding', function (): void {
    $page = visit('/');
    expect($page->script('() => getComputedStyle(document.querySelector("[data-row]")).paddingTop'))->toBe('8px');
});
