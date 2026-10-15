<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail_symfony\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\html_mail\HtmlMailRenderer;
use Drupal\html_mail_symfony\SymfonyMailerHtml;
use Drupal\symfony_mailer\EmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

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
   * Creates a mock email for a legacy (hook_mail) message.
   */
  protected function email(string $id): EmailInterface&MockObject {
    $email = $this->createMock(EmailInterface::class);
    $email->method('getParam')->with('legacy_message')->willReturn(['id' => $id]);
    return $email;
  }

  /**
   * Messages from listed modules get the template, others don't.
   */
  public function testPostRender(): void {
    $renderer = $this->createMock(HtmlMailRenderer::class);
    $renderer->expects($this->once())
      ->method('build')
      ->with($this->callback(fn (array $message): bool => $message['id'] === 'user_password_reset'))
      ->willReturn(['html' => '<p>HTML</p>', 'text' => 'Text']);
    $service = $this->service(['user'], $renderer);

    // 'user' is in the list, 'contact' isn't and has no hints.
    $reset = ['id' => 'user_password_reset', 'module' => 'user'];
    $contact = ['id' => 'contact_page_mail', 'module' => 'contact'];
    $service->mailAlter($reset);
    $service->mailAlter($contact);

    $kept = $this->email('user_password_reset');
    $kept->expects($this->once())->method('setHtmlBody')->with('<p>HTML</p>');
    $kept->expects($this->once())->method('setTextBody')->with('Text');
    $service->postRender($kept);

    $other = $this->email('contact_page_mail');
    $other->expects($this->never())->method('setHtmlBody');
    $service->postRender($other);

    // Calling it again shouldn't rebuild the message a second time.
    $service->postRender($kept);
  }

  /**
   * Changes made by alter hooks that run after ours are picked up.
   */
  public function testLaterAltersAreKept(): void {
    $renderer = $this->createMock(HtmlMailRenderer::class);
    $renderer->expects($this->once())
      ->method('build')
      ->with($this->callback(fn (array $message): bool => $message['body'] === ['Changed later.'] && $message['html_mail']['heading'] === 'Added later'))
      ->willReturn(['html' => '<p>HTML</p>', 'text' => 'Text']);
    $service = $this->service([], $renderer);

    $message = ['id' => 'contact_page_mail', 'module' => 'contact', 'body' => ['Original.']];
    $service->mailAlter($message);
    // Simulate another module's alter hook running after ours. It changes the
    // body and adds hints, which should be enough for contact's message to get
    // the template even though contact isn't in the module list.
    $message['body'] = ['Changed later.'];
    $message['html_mail'] = ['heading' => 'Added later'];

    $service->postRender($this->email('contact_page_mail'));
  }

  /**
   * A cancelled send doesn't leak into the next message with the same ID.
   */
  public function testCancelledSendIsDropped(): void {
    $renderer = $this->createMock(HtmlMailRenderer::class);
    $renderer->expects($this->once())
      ->method('build')
      ->with($this->callback(fn (array $message): bool => $message['body'] === ['Second.']))
      ->willReturn(['html' => '<p>HTML</p>', 'text' => 'Text']);
    $service = $this->service(['user'], $renderer);

    // The first send gets cancelled, so symfony_mailer never renders it.
    $first = ['id' => 'user_password_reset', 'module' => 'user', 'body' => ['First.'], 'send' => FALSE];
    $service->mailAlter($first);

    // Now another message with the same ID comes through. Our alter hook
    // doesn't see this one, so nothing should be rendered from the stale
    // first message.
    $email = $this->email('user_password_reset');
    $email->expects($this->once())->method('addCallback');
    $service->mailerInit($email);
    $email->expects($this->never())->method('setHtmlBody');
    $service->postRender($email);

    $second = ['id' => 'user_password_reset', 'module' => 'user', 'body' => ['Second.']];
    $service->mailAlter($second);
    $service->postRender($this->email('user_password_reset'));
  }

}
