<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail\Kernel;

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
      'body' => ["Hello <there>.\n\nYour code is 987654.", $url, 'See https://example.org/help for help.'],
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
   * Uses the configured site URL without its trailing slash.
   */
  public function testSiteUrl(): void {
    $this->assertSame('https://example.com', $this->container->get('html_mail.renderer')->siteUrl());
  }

}
