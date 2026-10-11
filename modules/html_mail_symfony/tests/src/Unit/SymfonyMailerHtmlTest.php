<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail_symfony\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\html_mail\HtmlMailRenderer;
use Drupal\html_mail_symfony\SymfonyMailerHtml;
use Drupal\symfony_mailer\EmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which messages get the template, and the post-render step.
 *
 * @group html_mail
 */
#[CoversClass(SymfonyMailerHtml::class)]
#[Group('html_mail')]
class SymfonyMailerHtmlTest extends UnitTestCase {

  /**
   * Builds the service with the given module list and a renderer mock.
   */
  protected function service(array $modules, ?HtmlMailRenderer $renderer = NULL): SymfonyMailerHtml {
    $config_factory = $this->getConfigFactoryStub([
      'html_mail_symfony.settings' => ['modules' => $modules],
    ]);
    return new SymfonyMailerHtml($config_factory, $renderer ?? $this->createMock(HtmlMailRenderer::class));
  }

  /**
   * Matches module names, and '*' for every module.
   */
  public function testModuleApplies(): void {
    $service = $this->service(['user']);
    $this->assertTrue($service->moduleApplies('user'));
    $this->assertFalse($service->moduleApplies('contact'));
    $this->assertTrue($this->service(['*'])->moduleApplies('contact'));
  }

  /**
   * Rebuilds a kept message's bodies, and leaves other messages alone.
   */
  public function testPostRender(): void {
    $renderer = $this->createMock(HtmlMailRenderer::class);
    $renderer->expects($this->once())
      ->method('build')
      ->with($this->callback(fn (array $message): bool => $message['id'] === 'user_password_reset'))
      ->willReturn(['html' => '<p>HTML</p>', 'text' => 'Text']);
    $service = $this->service(['user'], $renderer);

    // Kept: the module is listed. Not kept: unlisted, without hints.
    $service->mailAlter(['id' => 'user_password_reset', 'module' => 'user']);
    $service->mailAlter(['id' => 'contact_page_mail', 'module' => 'contact']);

    $kept = $this->createMock(EmailInterface::class);
    $kept->method('getParam')->with('legacy_message')->willReturn(['id' => 'user_password_reset']);
    $kept->expects($this->once())->method('setHtmlBody')->with('<p>HTML</p>');
    $kept->expects($this->once())->method('setTextBody')->with('Text');
    $service->postRender($kept);

    $other = $this->createMock(EmailInterface::class);
    $other->method('getParam')->with('legacy_message')->willReturn(['id' => 'contact_page_mail']);
    $other->expects($this->never())->method('setHtmlBody');
    $service->postRender($other);

    // A message is rebuilt once only.
    $service->postRender($kept);
  }

}
