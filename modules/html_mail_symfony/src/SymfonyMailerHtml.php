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
 * drops the html_mail hints. So we grab the message in hook_mail_alter() and,
 * once symfony_mailer has rendered, swap in the html_mail template for its
 * HTML and text.
 *
 * We keep a reference to the message rather than a copy. symfony_mailer
 * passes the same array to every hook_mail_alter() implementation, so by the
 * time we render we see whatever the last alter hook did to it, no matter
 * which order the hooks ran in.
 */
class SymfonyMailerHtml {

  /**
   * References to the messages being sent, keyed by message ID (module_key).
   *
   * Our hook_mail_alter() and the post-render step run in the same send, one
   * message at a time. If a send gets cancelled, its entry is cleaned up when
   * the next message with the same ID comes through mailerInit().
   *
   * @var array<string, array>
   */
  protected array $pending = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected HtmlMailRenderer $renderer,
  ) {}

  /**
   * Holds on to a message from hook_mail_alter().
   *
   * @param array $message
   *   The message being altered. We don't decide here whether it gets the
   *   template; postRender() does that once all the alter hooks have run.
   */
  public function mailAlter(array &$message): void {
    if (!empty($message['id'])) {
      $this->pending[$message['id']] = &$message;
    }
  }

  /**
   * Adds the post-render step to legacy (hook_mail()) emails.
   */
  public function mailerInit(EmailInterface $email): void {
    $legacy = $email->getParam('legacy_message');
    if ($legacy === NULL) {
      return;
    }
    // If an earlier message with this ID was cancelled after the alter hooks
    // ran ($message['send'] = FALSE), its entry is still here. Clear it so it
    // can't be mistaken for this one.
    if (isset($legacy['id'])) {
      unset($this->pending[$legacy['id']]);
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
    if (empty($message['html_mail']) && empty($message['params']['html_mail'])
      && !$this->moduleApplies((string) ($message['module'] ?? ''))) {
      return;
    }

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
   * Formats the module list for the settings form's textarea.
   *
   * A #config_target fromConfig callback.
   *
   * @param mixed $modules
   *   The html_mail_symfony.settings:modules value.
   */
  public static function modulesToText(mixed $modules): string {
    return implode("\n", (array) $modules);
  }

  /**
   * Parses the settings form's textarea into a module list.
   *
   * A #config_target toConfig callback.
   *
   * @param mixed $value
   *   Machine names separated by new lines, spaces or commas; '*' for all.
   *
   * @return string[]
   *   The valid, unique names.
   */
  public static function textToModules(mixed $value): array {
    $names = preg_split('/[\s,]+/', (string) $value) ?: [];
    return array_values(array_unique(array_filter(
      array_map('trim', $names),
      static fn (string $name): bool => $name === '*' || (bool) preg_match('/^[a-z0-9_]+$/', $name),
    )));
  }

}
