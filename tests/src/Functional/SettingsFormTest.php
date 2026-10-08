<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the settings form and the test message.
 *
 * @group html_mail
 */
#[Group('html_mail')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['html_mail'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Saves the settings, then sends a test message that uses them.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('admin/config/system/html-mail');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer html_mail']));
    $this->drupalGet('admin/config/system/html-mail');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['site_url' => 'ftp://example.com'], 'Save configuration');
    $this->assertSession()->pageTextContains('The site URL must start with http:// or https://.');

    $this->submitForm([
      'site_url' => 'https://example.com/',
      'brand_name' => 'Acme',
      'logo_url' => '/logo.png',
      'colors[accent]' => '#FF0066',
      'footer' => '<p>Sent by Acme</p>',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $config = $this->config('html_mail.settings');
    $this->assertSame('https://example.com', $config->get('site_url'));
    $this->assertSame('Acme', $config->get('brand_name'));
    $this->assertSame('#ff0066', $config->get('colors.accent'));

    // The test collector keeps the formatted message; the renderer's output
    // is checked directly.
    $this->submitForm(['test_to' => 'someone@example.com'], 'Send test message');
    $this->assertSession()->pageTextContains('Test message sent to someone@example.com.');
    $this->assertMailString('subject', 'HTML Mail test message', 1);
    $mails = $this->getMails(['id' => 'html_mail_test']);
    $this->assertCount(1, $mails);
    $html = \Drupal::service('html_mail.renderer')->build($mails[0])['html'];
    $this->assertStringContainsString('src="https://example.com/logo.png"', $html);
    $this->assertStringContainsString('background:#ff0066', $html);
    $this->assertStringContainsString('123456', $html);
    $this->assertStringContainsString('Sent by Acme', $html);
  }

}
