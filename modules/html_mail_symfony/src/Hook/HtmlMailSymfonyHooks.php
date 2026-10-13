<?php

declare(strict_types=1);

namespace Drupal\html_mail_symfony\Hook;

use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\html_mail_symfony\SymfonyMailerHtml;
use Drupal\symfony_mailer\EmailInterface;

/**
 * Hook implementations for Magic HTML Mail: Symfony Mailer.
 *
 * The work is done by the html_mail_symfony.mailer service.
 */
class HtmlMailSymfonyHooks {

  use StringTranslationTrait;

  public function __construct(
    protected SymfonyMailerHtml $mailer,
  ) {}

  /**
   * Implements hook_mail_alter().
   */
  #[Hook('mail_alter')]
  public function mailAlter(array &$message): void {
    $this->mailer->mailAlter($message);
  }

  /**
   * Implements hook_mailer_init().
   */
  #[Hook('mailer_init')]
  public function mailerInit(EmailInterface $email): void {
    $this->mailer->mailerInit($email);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for html_mail_settings.
   *
   * The settings form is a ConfigFormBase, so the #config_target saves the
   * value.
   */
  #[Hook('form_html_mail_settings_alter')]
  public function formHtmlMailSettingsAlter(array &$form, FormStateInterface $form_state): void {
    $form['symfony_mailer'] = [
      '#type' => 'details',
      '#title' => $this->t('Symfony Mailer'),
      '#open' => TRUE,
      '#weight' => 5,
    ];
    $form['symfony_mailer']['html_mail_symfony_modules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Modules whose email always uses the template'),
      '#config_target' => new ConfigTarget(
        'html_mail_symfony.settings',
        'modules',
        [SymfonyMailerHtml::class, 'modulesToText'],
        [SymfonyMailerHtml::class, 'textToModules'],
      ),
      '#rows' => 3,
      '#description' => $this->t("Machine names, one per line, e.g. <code>user</code> for core account emails. <code>*</code> means every module. Messages with <code>html_mail</code> hints always use the template; other messages keep Symfony Mailer's own wrapping."),
    ];
  }

}
