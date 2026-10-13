<?php

declare(strict_types=1);

namespace Drupal\html_mail\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\html_mail\HtmlMailRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Magic HTML Mail settings: site URL, theme, branding, and a test message.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ThemeHandlerInterface $themeHandler,
    protected MailManagerInterface $mailManager,
    protected AccountProxyInterface $currentUser,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('theme_handler'),
      $container->get('plugin.manager.mail'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'html_mail_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['html_mail.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['site'] = [
      '#type' => 'details',
      '#title' => $this->t('Site'),
      '#open' => TRUE,
    ];
    $form['site']['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Site URL'),
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'site_url',
        toConfig: [static::class, 'siteUrlToConfig'],
      ),
      '#placeholder' => 'https://example.com',
      '#description' => $this->t('Absolute base URL for links and images in emails. Leave empty to use the current request, but set it if mail is sent from cron or drush, where there is no real host.'),
    ];
    $themes = [];
    foreach ($this->themeHandler->listInfo() as $name => $theme) {
      $themes[$name] = $theme->info['name'] ?? $name;
    }
    asort($themes);
    $form['site']['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Mail theme'),
      '#options' => $themes,
      '#empty_option' => $this->t('- Default theme -'),
      '#empty_value' => '',
      '#config_target' => 'html_mail.settings:theme',
      '#description' => $this->t('The theme whose <code>html-mail.html.twig</code> renders every email, even mail sent from admin pages, drush or cron.'),
    ];

    $form['branding'] = [
      '#type' => 'details',
      '#title' => $this->t('Branding'),
      '#open' => TRUE,
      '#description' => $this->t('Used by the default template. A theme that overrides the template receives these as the <code>site_name</code>, <code>logo_url</code>, <code>logo_width</code> and <code>style</code> variables.'),
    ];
    $form['branding']['brand_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'brand_name',
        toConfig: [static::class, 'trimToConfig'],
      ),
      '#placeholder' => $this->config('system.site')->get('name'),
      '#description' => $this->t('Shown in the header and footer. Leave empty to use the site name.'),
    ];
    $form['branding']['logo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logo'),
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'logo_url',
        toConfig: [static::class, 'trimToConfig'],
      ),
      '#maxlength' => 2048,
      '#placeholder' => '/themes/custom/example/images/logo.png',
      '#description' => $this->t('An absolute URL, or a path on the site URL. Use PNG or JPEG: many email clients do not show SVG or WebP. Leave empty to show the name as text.'),
    ];
    $form['branding']['logo_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Logo width'),
      '#field_suffix' => 'px',
      '#min' => 16,
      '#max' => 600,
      '#required' => TRUE,
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'logo_width',
        toConfig: [static::class, 'intToConfig'],
      ),
    ];
    $form['branding']['font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Font stack'),
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'font_family',
        toConfig: [static::class, 'trimToConfig'],
      ),
      '#placeholder' => HtmlMailRenderer::DEFAULT_FONT,
      '#maxlength' => 512,
      '#description' => $this->t('A CSS font-family list. Most email clients ignore web fonts, so end with fonts every system has.'),
    ];

    $labels = [
      'text' => $this->t('Text'),
      'muted' => $this->t('Secondary text'),
      'accent' => $this->t('Accent (links, buttons)'),
      'accent_text' => $this->t('Button text'),
      'background' => $this->t('Page background'),
      'surface' => $this->t('Card background'),
      'panel' => $this->t('Callout and code background'),
      'border' => $this->t('Borders'),
      'badge' => $this->t('Badge background'),
    ];
    $form['branding']['colors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Colors'),
      '#tree' => TRUE,
    ];
    foreach ($labels as $key => $label) {
      $form['branding']['colors'][$key] = [
        '#type' => 'color',
        '#title' => $label,
        '#config_target' => new ConfigTarget(
          'html_mail.settings',
          'colors.' . $key,
          toConfig: [static::class, 'colorToConfig'],
        ),
      ];
    }
    $form['branding']['footer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default footer'),
      '#config_target' => new ConfigTarget(
        'html_mail.settings',
        'footer',
        toConfig: [static::class, 'trimToConfig'],
      ),
      '#rows' => 3,
      '#description' => $this->t('HTML for the footer of messages that do not set their own, such as a postal address or why people receive email. Plain tags only.'),
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Send a test message'),
      '#description' => $this->t('Sends a message that uses every part of the template, with the saved settings, through the site mail system.'),
    ];
    $form['test']['test_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#default_value' => $this->currentUser->getEmail(),
    ];
    $form['test']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send test message'),
      '#submit' => ['::sendTest'],
      '#limit_validation_errors' => [['test_to']],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $url = trim((string) $form_state->getValue('site_url'));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
      $form_state->setErrorByName('site_url', $this->t('The site URL must start with http:// or https://.'));
    }
  }

  /**
   * Config target callback: trims a submitted string.
   */
  public static function trimToConfig(mixed $value): string {
    return trim((string) $value);
  }

  /**
   * Config target callback: the site URL, trimmed, without a trailing slash.
   */
  public static function siteUrlToConfig(mixed $value): string {
    return rtrim(trim((string) $value), '/');
  }

  /**
   * Config target callback: a submitted number as an integer.
   */
  public static function intToConfig(mixed $value): int {
    return (int) $value;
  }

  /**
   * Config target callback: a submitted color in lowercase.
   */
  public static function colorToConfig(mixed $value): string {
    return strtolower((string) $value);
  }

  /**
   * Submit handler: sends the test message.
   */
  public function sendTest(array &$form, FormStateInterface $form_state): void {
    $to = (string) $form_state->getValue('test_to');
    if ($to === '') {
      $this->messenger()->addError($this->t('Enter a recipient for the test message.'));
      return;
    }
    $result = $this->mailManager->mail('html_mail', 'test', $to, $this->currentUser->getPreferredLangcode(), []);
    if (!empty($result['result'])) {
      $this->messenger()->addStatus($this->t('Test message sent to %to.', ['%to' => $to]));
    }
    else {
      $this->messenger()->addError($this->t('The test message could not be sent. Check the site log.'));
    }
  }

}
