<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Support/helpers.php';

final class EscapingTest extends TestCase
{
    public function test_T05_escapes_both_quote_types_and_html_metacharacters(): void
    {
        self::assertSame('&lt;&amp;&quot;&#039;&gt;', e('<&"\'>'));
    }

    public function test_T05_null_and_numeric_values_render_safely(): void
    {
        self::assertSame('', e(null));
        self::assertSame('123', e(123));
        self::assertSame('12.5', e(12.5));
    }

    public function test_T05_invalid_utf8_is_substituted_instead_of_discarding_the_text(): void
    {
        self::assertSame("FAKE\u{FFFD}", e("FAKE\xFF"));
    }
}
