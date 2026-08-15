<?php

/**
 * The assistant bubble renders model output through Str::markdown() with
 * {!! !!}. league/commonmark defaults to html_input=ALLOW, so without an
 * explicit override, raw HTML in an assistant message (which is untrusted:
 * it can be steered via prompt injection) would pass through unescaped and
 * execute in the panel. The view now passes html_input from
 * config('filament-copilot.chat.html_input'), defaulting to 'escape'.
 *
 * Helper name is suffixed to avoid colliding with renderChatMessage() in
 * ChatMessageFeedbackTest.php — Pest loads every test file into one process,
 * so top-level helper functions must be unique package-wide.
 */
function renderChatMessageForXssTest(string $content): string
{
    return view('filament-copilot::components.chat-message', [
        'msg' => ['role' => 'assistant', 'content' => $content],
    ])->render();
}

it('still renders assistant markdown', function () {
    $html = renderChatMessageForXssTest('**bold** text');

    expect($html)->toContain('<strong>bold</strong>');
});

it('escapes raw html in an assistant message by default', function () {
    $html = renderChatMessageForXssTest("Here you go:\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");

    expect($html)
        ->not->toContain('<script>')
        ->not->toContain('alert(1)</script>')
        ->not->toContain('<img ')
        // Escaped, not silently dropped -- the user still sees what was said.
        ->toContain('&lt;script&gt;');
});

it('defuses javascript: links in an assistant message', function () {
    $html = renderChatMessageForXssTest('[click me](javascript:alert(1))');

    expect($html)
        ->not->toContain('javascript:')
        ->toContain('click me');
});

it('escapes svg and iframe payloads in an assistant message', function () {
    $html = renderChatMessageForXssTest("<svg onload=alert(1)></svg>\n\n<iframe src=javascript:alert(1)></iframe>");

    // The component's own icons are inline <svg> elements, so assert on the
    // payload markup specifically rather than on any <svg> tag.
    expect($html)
        ->not->toContain('<svg onload')
        ->not->toContain('<iframe')
        ->toContain('&lt;svg onload')
        ->toContain('&lt;iframe');
});

it('defuses dangerous image sources in an assistant message', function () {
    // Images go through a different renderer than links (src is emptied rather
    // than href dropped) -- pin both paths so neither can regress alone.
    $html = renderChatMessageForXssTest("![a](javascript:alert(1))\n\n![b](data:text/html,<script>alert(1)</script>)");

    expect($html)
        ->not->toContain('javascript:')
        ->not->toContain('data:text/html');
});

it('defuses javascript: links even when html_input is allow', function () {
    // Link safety comes from allow_unsafe_links=false, independently of
    // html_input -- this must hold even for consumers who opt into raw HTML.
    config()->set('filament-copilot.chat.html_input', 'allow');

    $html = renderChatMessageForXssTest('[click me](javascript:alert(1))');

    expect($html)
        ->not->toContain('javascript:')
        ->toContain('click me');
});

it('restores raw html rendering when chat.html_input is set to allow', function () {
    config()->set('filament-copilot.chat.html_input', 'allow');

    $html = renderChatMessageForXssTest('<mark>highlighted</mark>');

    expect($html)->toContain('<mark>highlighted</mark>');
});

it('strips raw html when chat.html_input is set to strip', function () {
    config()->set('filament-copilot.chat.html_input', 'strip');

    $html = renderChatMessageForXssTest("<script>alert(1)</script>\n\nplain text");

    expect($html)
        ->not->toContain('<script>')
        ->not->toContain('&lt;script&gt;')
        ->toContain('plain text');
});
