<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail_ses\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that uninstalling the module doesn't leave mail pointing at it.
 *
 * @group html_mail
 */
#[Group('html_mail')]
#[RunTestsInSeparateProcesses]
class UninstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Checks that system.mail entries using html_mail_ses are switched back.
   */
  public function testUninstall(): void {
    $installer = $this->container->get('module_installer');
    $installer->install(['html_mail_ses']);
    $this->config('system.mail')->set('interface', [
      'default' => 'html_mail_ses',
      'user' => 'html_mail_ses',
      'contact' => 'php_mail',
    ])->save();

    $installer->uninstall(['html_mail_ses']);

    $this->assertSame([
      'default' => 'amazon_ses_mail',
      'user' => 'amazon_ses_mail',
      'contact' => 'php_mail',
    ], $this->config('system.mail')->get('interface'));
  }

}
