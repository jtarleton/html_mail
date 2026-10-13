<?php

declare(strict_types=1);

namespace Drupal\html_mail\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\html_mail\HtmlMailRenderer;

/**
 * Hook implementations for Magic HTML Mail.
 */
class HtmlMailHooks {

  use StringTranslationTrait;

  public function __construct(
    protected HtmlMailRenderer $renderer,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name === 'help.page.html_mail' || $route_name === 'html_mail.settings') {
      $text = '<p>' . $this->t('Magic HTML Mail sends email as HTML from one template, <code>html-mail.html.twig</code>, with a plain-text alternative. The settings below brand the default template; a theme can replace it entirely by overriding the template.') . '</p>';
      if ($route_name === 'help.page.html_mail') {
        $text .= '<p>' . $this->t('Enable a transport submodule too: <em>Magic HTML Mail: Symfony Mailer</em> or <em>Magic HTML Mail: Amazon SES</em>. See the README for the hints a sending module can add to its messages.') . '</p>';
      }
      return $text;
    }
    return '';
  }

  /**
   * Implements hook_mail().
   *
   * The 'test' message sent from the settings page.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    if ($key !== 'test') {
      return;
    }
    $options = ['langcode' => $message['langcode']];
    $site = $this->renderer->siteUrl();
    $message['subject'] = $this->t('Magic HTML Mail test message', [], $options);
    $message['body'][] = $this->t('This is a test message from Magic HTML Mail. If it shows a heading, a code and a button, HTML email works on this site.', [], $options);
    $message['body'][] = $site;
    $message['body'][] = $this->t('The rest of a message follows the button.', [], $options);
    $message['html_mail'] = [
      'eyebrow' => $this->t('Test', [], $options),
      'heading' => $this->t('HTML email works', [], $options),
      'code' => '123456',
      'button' => ['label' => $this->t('Visit the site', [], $options), 'url' => $site],
      'callout' => [
        'eyebrow' => $this->t('Example', [], $options),
        'label' => $this->t('A summary card', [], $options),
        'value' => '42',
        'rows' => [
          ['label' => $this->t('A row', [], $options), 'value' => '1', 'badge' => $this->t('New', [], $options)],
          [
            'label' => $this->t('Another row', [], $options),
            'value' => '2',
            'note' => $this->t('with a note', [], $options),
          ],
        ],
      ],
      'notice' => $this->t('You received this because someone sent a test message from the Magic HTML Mail settings page.', [], $options),
    ];
  }

  /**
   * Implements hook_theme().
   *
   * Variables (all optional except body/subject; see README.md):
   * - subject, preheader, langcode, site_url
   * - site_name: html_mail.settings:brand_name, or the site name
   * - logo_url, logo_width: the logo from html_mail.settings (absolute URL)
   * - style: html_mail.settings colors and font: font, text, muted, accent,
   *   accent_text, background, surface, panel, border, badge
   * - eyebrow: a short line above everything (e.g. a date)
   * - greeting, heading
   * - callout: a summary card: ['eyebrow', 'label', 'value', 'note',
   *   'rows' => [['label', 'value', 'badge', 'note'], ...]]
   * - code: a one-time code, shown large
   * - body: the message, as safe HTML of plain tags (p, h2, h3, ul, ol, li,
   *   strong, em, a, br); style it with the |mail_style filter
   * - body_after: more of the message, shown after the button (the renderer
   *   splits a plain-text body where the button's link stood on its own line)
   * - button, secondary_button: ['label', 'url']
   * - fallback_url: shown under the button as a copyable link
   * - notice: small print under the content
   * - footer: why the email was sent, as safe HTML (default:
   *   html_mail.settings:footer)
   * - unsubscribe_url.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'html_mail' => [
        'variables' => [
          'subject' => '',
          'preheader' => '',
          'langcode' => 'en',
          'site_name' => '',
          'site_url' => '',
          'logo_url' => '',
          'logo_width' => 160,
          'style' => [],
          'eyebrow' => '',
          'greeting' => '',
          'heading' => '',
          'callout' => NULL,
          'code' => '',
          'body' => '',
          'body_after' => '',
          'button' => NULL,
          'secondary_button' => NULL,
          'fallback_url' => '',
          'notice' => '',
          'footer' => '',
          'unsubscribe_url' => '',
        ],
      ],
    ];
  }

}
