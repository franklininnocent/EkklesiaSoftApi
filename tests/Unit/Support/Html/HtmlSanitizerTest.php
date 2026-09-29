<?php

namespace Tests\Unit\Support\Html;

use App\Support\Html\HtmlSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer;
    }

    #[Test]
    public function it_allows_safe_formatting(): void
    {
        $html = '<p>Hello <strong>world</strong></p><ul><li>One</li></ul><a href="https://example.com">Link</a>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertNotNull($result);
        $this->assertStringContainsString('<strong>world</strong>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    #[Test]
    public function it_strips_script_tags(): void
    {
        $result = $this->sanitizer->sanitize('<p>Safe</p><script>alert(1)</script>');

        $this->assertSame('<p>Safe</p>', $result);
        $this->assertStringNotContainsString('script', strtolower((string) $result));
    }

    #[Test]
    public function it_removes_javascript_urls(): void
    {
        $result = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('javascript:', strtolower($result));
    }

    #[Test]
    public function it_strips_event_handler_attributes(): void
    {
        $result = $this->sanitizer->sanitize('<p onclick="alert(1)">Text</p>');

        $this->assertSame('<p>Text</p>', $result);
        $this->assertStringNotContainsString('onclick', strtolower((string) $result));
    }

    #[Test]
    public function it_returns_null_for_empty_markup(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertNull($this->sanitizer->sanitize(''));
        $this->assertNull($this->sanitizer->sanitize('<p></p>'));
        $this->assertNull($this->sanitizer->sanitize('<p><br></p>'));
    }

    #[Test]
    public function it_allows_strike_alignment_and_horizontal_rule(): void
    {
        $html = '<p style="text-align:center"><s>Done</s></p><hr><blockquote>Quote</blockquote>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertNotNull($result);
        $this->assertStringContainsString('text-align:center', str_replace(' ', '', $result ?? ''));
        $this->assertMatchesRegularExpression('/<(s|strike|del)>/i', (string) $result);
        $this->assertMatchesRegularExpression('/<hr\b/i', (string) $result);
        $this->assertStringContainsString('<blockquote>', (string) $result);
    }

    #[Test]
    public function it_strips_unsafe_css_while_keeping_text_align(): void
    {
        $result = $this->sanitizer->sanitize('<p style="text-align:right; color:red; background:url(x)">Aligned</p>');

        $this->assertNotNull($result);
        $this->assertStringContainsString('text-align:right', str_replace(' ', '', $result ?? ''));
        $this->assertStringNotContainsString('color', strtolower((string) $result));
        $this->assertStringNotContainsString('background', strtolower((string) $result));
    }

    #[Test]
    public function it_sanitizes_named_fields(): void
    {
        $result = $this->sanitizer->sanitizeFields([
            'description' => '<p>Ok</p><script>bad()</script>',
            'vision' => '<p></p>',
            'name' => 'Keep me',
        ], ['description', 'vision']);

        $this->assertSame('<p>Ok</p>', $result['description']);
        $this->assertNull($result['vision']);
        $this->assertSame('Keep me', $result['name']);
    }
}
