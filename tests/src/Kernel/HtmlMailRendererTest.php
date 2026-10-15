<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail\Kernel;

use Drupal\Core\Render\Markup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\html_mail\HtmlMailRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests building an email's HTML and plain text.
 *
 * @group html_mail
 */
#[CoversClass(HtmlMailRenderer::class)]
#[Group('html_mail')]
#[RunTestsInSeparateProcesses]
class HtmlMailRendererTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'html_mail'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'html_mail']);
    $this->config('system.site')->set('name', 'Example Site')->save();
    $this->config('html_mail.settings')->set('site_url', 'https://example.com/')->save();
  }

  /**
   * Builds a message with hints: code, button, linkified text.
   */
  public function testBuildWithHints(): void {
    $url = 'https://example.com/confirm?token=abc';
    $built = $this->container->get('html_mail.renderer')->build([
      'subject' => 'Confirm',
      'langcode' => 'en',
      'body' => ["Hello &lt;there&gt;.\n\nYour code is 987654.", $url, 'See https://example.org/help for help.'],
      'html_mail' => [
        'heading' => 'Confirm your email',
        'code' => '987654',
        'button' => ['label' => 'Confirm', 'url' => $url],
      ],
    ]);
    $html = $built['html'];

    $this->assertStringContainsString('Confirm your email', $html);
    $this->assertStringContainsString('987654', $html);
    $this->assertStringContainsString('Hello &lt;there&gt;.', $html);
    $this->assertStringContainsString('Hello <there>.', $built['text']);
    $this->assertStringContainsString('href="https://example.org/help">https://example.org/help</a>', $html);
    $this->assertStringContainsString('href="https://example.com"', $html);
    $this->assertStringContainsString('Example Site', $html);
    // The button sits between the text before and after its URL's line.
    $this->assertLessThan(strpos($html, 'example.org/help'), strpos($html, '>Confirm</a>'));
    $this->assertGreaterThan(strpos($html, 'Hello'), strpos($html, '>Confirm</a>'));
    // The paragraph repeating the code is left out of the HTML only.
    $this->assertStringNotContainsString('Your code is', $html);
    $this->assertStringContainsString('Your code is 987654.', $built['text']);
    $this->assertStringContainsString($url, $built['text']);
    $this->assertStringContainsString('#0b5cad', $html);
  }

  /**
   * The body is handled as HTML, the same way core's mail plugin does it.
   */
  public function testBodyIsReadAsHtml(): void {
    $long = str_repeat('A long sentence that the plain text wraps. ', 4);
    $built = $this->container->get('html_mail.renderer')->build([
      'subject' => 'Hi',
      'body' => [
        // What user_mail() produces: token values are HTML-escaped.
        "Welcome to O&#039;Brien Dental.\n\n" . $long,
        'See &lt;https://example.com/a?b=1&amp;c=2&gt; for help.',
        // What the contact module produces: rendered markup.
        Markup::create("<div><div>Message</div>\n<div>Hello <strong>there</strong></div></div>"),
      ],
    ]);
    $html = $built['html'];
    $text = $built['text'];

    $this->assertStringContainsString("Welcome to O'Brien Dental.", $text);
    $this->assertStringContainsString('Welcome to O&#039;Brien Dental.', $html);
    $this->assertStringNotContainsString('&amp;#039;', $html);
    $this->assertStringNotContainsString('&#039;', $text);
    // Tags get converted, not printed literally.
    $this->assertStringContainsString('*there*', $text);
    $this->assertStringNotContainsString('<div>', $text);
    $this->assertStringNotContainsString('&lt;div', $html);
    $this->assertStringNotContainsString('&lt;strong', $html);
    // Lines wrapped in the text version are joined back up in the HTML.
    $this->assertStringContainsString(trim($long), $html);
    // The link shouldn't swallow the escaped closing bracket.
    $this->assertStringContainsString('See &lt;<a style="color:#0b5cad;" href="https://example.com/a?b=1&amp;c=2">https://example.com/a?b=1&amp;c=2</a>&gt; for help.', $html);
    // The preview text is escaped only once, and paragraphs are separated
    // by a space.
    $this->assertMatchesRegularExpression('#display:none[^>]*>Welcome to O&\#039;Brien Dental\. A long sentence#', $html);
  }

  /**
   * The mail_style filter escapes plain strings before styling them.
   */
  public function testMailStyleEscapesStrings(): void {
    $twig = $this->container->get('twig');
    $template = "{{ value|mail_style({p: 'margin:0;'}) }}";
    $plain = (string) $twig->createTemplate($template)->render(['value' => '<p>Hi</p>']);
    $this->assertSame('&lt;p&gt;Hi&lt;/p&gt;', $plain);
    $markup = (string) $twig->createTemplate($template)->render(['value' => Markup::create('<p>Hi</p>')]);
    $this->assertSame('<p style="margin:0;">Hi</p>', $markup);
  }

  /**
   * Applies the branding settings: name, logo, colors, font, footer.
   */
  public function testBranding(): void {
    $this->config('html_mail.settings')
      ->set('brand_name', 'Acme')
      ->set('logo_url', '/images/logo.png')
      ->set('logo_width', 120)
      ->set('colors.accent', '#ff0066')
      ->set('font_family', 'Georgia,serif')
      ->set('footer', '<p>Sent by Acme<script>alert(1)</script></p>')
      ->save();
    $html = $this->container->get('html_mail.renderer')->build([
      'subject' => 'Hi',
      'body' => ['Hello.'],
      'html_mail' => ['button' => ['label' => 'Go', 'url' => 'https://example.com/go']],
    ])['html'];

    $this->assertStringContainsString('<img src="https://example.com/images/logo.png" width="120" alt="Acme"', $html);
    $this->assertStringNotContainsString('Example Site', $html);
    $this->assertStringContainsString('background:#ff0066', $html);
    $this->assertStringContainsString('font-family:Georgia,serif;', $html);
    $this->assertStringContainsString('Sent by Acme', $html);
    $this->assertStringNotContainsString('<script>', $html);
  }

  /**
   * Filters HTML hints and drops dangerous URL protocols.
   */
  public function testHintsAreSanitized(): void {
    $html = $this->container->get('html_mail.renderer')->build([
      'subject' => 'Hi',
      'body' => ['Hello.'],
      'html_mail' => [
        'body' => '<p onclick="alert(1)">Kept <strong>bold</strong></p><script>alert(2)</script><img src="x" onerror="alert(3)">',
        'footer' => '<p>Footer<iframe src="https://example.org"></iframe></p>',
        'button' => ['label' => 'Go', 'url' => 'javascript:alert(4)'],
        'secondary_button' => ['label' => 'More', 'url' => 'https://example.com/more'],
        'fallback_url' => 'javascript:alert(5)',
        'unsubscribe_url' => 'data:text/html,unsubscribe',
      ],
    ])['html'];

    $this->assertStringContainsString('Kept <strong>bold</strong>', $html);
    $this->assertStringContainsString('Footer', $html);
    $this->assertStringContainsString('href="https://example.com/more"', $html);
    foreach (['onclick', '<script', '<img src="x"', 'onerror', '<iframe', 'javascript:', 'data:text'] as $unsafe) {
      $this->assertStringNotContainsString($unsafe, $html);
    }
  }

  /**
   * Uses the configured site URL without its trailing slash.
   */
  public function testSiteUrl(): void {
    $this->assertSame('https://example.com', $this->container->get('html_mail.renderer')->siteUrl());
  }

}
