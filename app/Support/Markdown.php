<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders untrusted Markdown (step descriptions, comments): raw HTML is stripped and
 * unsafe link schemes (javascript:, vbscript:, file:, data: other than images) are dropped.
 */
final class Markdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    public static function toHtml(string $markdown): string
    {
        self::$converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        return self::$converter->convert($markdown)->getContent();
    }
}
