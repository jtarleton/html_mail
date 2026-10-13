<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail_symfony\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Symfony Mailer part of the Magic HTML Mail settings form.
 *
 * @group html_mail
 */
#[Group('html_mail')]
#[RunTestsInSeparateProcesses]
class SettingsFormAlterTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['html_mail_symfony'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Saves the module list through the altered settings form.
   */
  public function testModules(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer html_mail']));
    $this->drupalGet('admin/config/system/html-mail');
    $this->assertSession()->fieldValueEquals('html_mail_symfony_modules', 'user');

    $this->submitForm([
      'html_mail_symfony_modules' => "user, contact\nNot-A-Module user\n*",
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame(['user', 'contact', '*'], $this->config('html_mail_symfony.settings')->get('modules'));
    $this->assertSession()->fieldValueEquals('html_mail_symfony_modules', "user\ncontact\n*");
  }

}
