<?php

declare(strict_types=1);

namespace Drupal\html_mail_ses\Plugin\Mail;

use Drupal\amazon_ses\Plugin\Mail\AmazonSes;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\html_mail\HtmlMailRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Amazon SES, sending the html_mail template with a plain-text alternative.
 *
 * Choose it per module or per message in system.mail:interface, e.g.
 * `drush cset system.mail interface.mymodule html_mail_ses`.
 *
 * The SES module's own plugin inherits core's PhpMail formatting, which turns
 * every message into plain text and drops headers it does not know; this one
 * keeps the HTML part and passes List-Unsubscribe / List-Unsubscribe-Post
 * through (one-click unsubscribe, RFC 8058).
 */
#[Mail(
  id: 'html_mail_ses',
  label: new TranslatableMarkup('HTML Mail (Amazon SES)'),
  description: new TranslatableMarkup('HTML from the html_mail template plus plain text, sent through Amazon SES.'),
)]
class HtmlMailSes extends AmazonSes {

  /**
   * Headers passed through to the sent message.
   */
  protected const PASS_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

  /**
   * The html_mail renderer.
   */
  protected HtmlMailRenderer $htmlMail;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->htmlMail = $container->get('html_mail.renderer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $built = $this->htmlMail->build($message);
    $message['body'] = $built['text'];
    $message['html_mail_rendered'] = $built['html'];
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $config = $this->configFactory->get('amazon_ses.settings');
    if ($config->get('override_from') || !isset($message['from'])) {
      $message['from'] = $config->get('from_name') . ' <' . $config->get('from_address') . '>';
    }
    $headers = $message['headers'] ?? [];
    $pass = array_intersect_key($headers, array_flip(self::PASS_HEADERS));
    // The builder reads the type from Content-Type: plain text, then the HTML
    // part is added alongside.
    $message['headers'] = ['Content-Type' => 'text/plain; charset=UTF-8'] + array_diff_key($headers, $pass + ['Content-Type' => TRUE]);

    $email = $this->messageBuilder->buildMessage($message);
    if (!empty($message['html_mail_rendered'])) {
      $email->html((string) $message['html_mail_rendered']);
    }
    foreach ($pass as $name => $value) {
      $email->getHeaders()->addTextHeader($name, (string) $value);
    }

    if ($config->get('queue')) {
      return (bool) $this->queueFactory->get('amazon_ses_mail_queue')->createItem($email);
    }
    return (bool) $this->handler->send($email);
  }

}
