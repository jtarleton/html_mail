<?php

declare(strict_types=1);

namespace Drupal\html_mail_symfony;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\html_mail\HtmlMailRenderer;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Sends the html_mail template through Drupal Symfony Mailer.
 *
 * With symfony_mailer, core's mail plugins (system.mail:interface) are never
 * used: every hook_mail() message goes through its LegacyEmailBuilder, which
 * keeps only the subject and body, wraps the body in email-wrap.html.twig and
 * drops the html_mail hints. So the finished message is kept from
 * hook_mail_alter() and, once symfony_mailer has rendered, rebuilt with the
 * html_mail template in place of its own HTML and text.
 */
class SymfonyMailerHtml {

  /**
   * Messages waiting for their HTML, keyed by message ID (module_key).
   *
   * The hook_mail_alter() implementation and the post-render step run in the
   * same send, one message at a time.
   *
   * @var array<string, array>
   */
  protected array $pending = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected HtmlMailRenderer $renderer,
  ) {}

  /**
   * Keeps a message that gets the template, from hook_mail_alter().
   *
   * @param array $message
   *   The message as hook_mail() and other hook_mail_alter()s left it.
   */
  public function mailAlter(array $message): void {
    if (empty($message['id'])) {
      return;
    }
    if (empty($message['html_mail']) && empty($message['params']['html_mail'])
      && !$this->moduleApplies((string) ($message['module'] ?? ''))) {
      return;
    }
    $this->pending[$message['id']] = $message;
  }

  /**
   * Adds the post-render step to legacy (hook_mail()) emails.
   */
  public function mailerInit(EmailInterface $email): void {
    if ($email->getParam('legacy_message') === NULL) {
      return;
    }
    // Weight 1000: after symfony_mailer's own wrapping (800) and CSS inlining
    // (900), so this has the last word on both bodies.
    $email->addCallback([$this, 'postRender'], EmailInterface::PHASE_POST_RENDER, 1000, 'html_mail_symfony');
  }

  /**
   * Post-render: replaces the bodies with the html_mail template's.
   */
  public function postRender(EmailInterface $email): void {
    $id = $email->getParam('legacy_message')['id'] ?? NULL;
    if ($id === NULL || !isset($this->pending[$id])) {
      return;
    }
    $message = $this->pending[$id];
    unset($this->pending[$id]);

    $built = $this->renderer->build($message);
    $email->setHtmlBody($built['html']);
    $email->setTextBody($built['text']);
  }

  /**
   * The modules whose messages get the template even without hints.
   *
   * @return string[]
   *   Module machine names; '*' for every module.
   */
  public function modules(): array {
    return (array) $this->configFactory->get('html_mail_symfony.settings')->get('modules');
  }

  /**
   * Whether a module's messages get the template even without hints.
   */
  public function moduleApplies(string $module): bool {
    $modules = $this->modules();
    return in_array('*', $modules, TRUE) || in_array($module, $modules, TRUE);
  }

  /**
   * Saves the module list from the settings form's textarea.
   *
   * @param string $value
   *   Machine names separated by new lines, spaces or commas; '*' for all.
   */
  public function saveModules(string $value): void {
    $names = preg_split('/[\s,]+/', $value) ?: [];
    $modules = array_values(array_unique(array_filter(
      array_map('trim', $names),
      static fn (string $name): bool => $name === '*' || (bool) preg_match('/^[a-z0-9_]+$/', $name),
    )));
    $this->configFactory->getEditable('html_mail_symfony.settings')
      ->set('modules', $modules)
      ->save();
  }

}
