<?php

declare(strict_types=1);

namespace Drupal\html_mail\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\html_mail\HtmlMailRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * HTML Mail settings: site URL, theme, branding, and a test message.
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
    $config = $this->config('html_mail.settings');

    $form['site'] = [
      '#type' => 'details',
      '#title' => $this->t('Site'),
      '#open' => TRUE,
    ];
    $form['site']['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Site URL'),
      '#default_value' => $config->get('site_url'),
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
      '#default_value' => $config->get('theme') ?? '',
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
      '#default_value' => $config->get('brand_name'),
      '#placeholder' => $this->config('system.site')->get('name'),
      '#description' => $this->t('Shown in the header and footer. Leave empty to use the site name.'),
    ];
    $form['branding']['logo_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logo'),
      '#default_value' => $config->get('logo_url'),
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
      '#default_value' => $config->get('logo_width') ?: 160,
    ];
    $form['branding']['font_family'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Font stack'),
      '#default_value' => $config->get('font_family') ?: HtmlMailRenderer::DEFAULT_FONT,
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
    $colors = (array) $config->get('colors') + HtmlMailRenderer::DEFAULT_COLORS;
    $form['branding']['colors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Colors'),
      '#tree' => TRUE,
    ];
    foreach ($labels as $key => $label) {
      $form['branding']['colors'][$key] = [
        '#type' => 'color',
        '#title' => $label,
        '#default_value' => $colors[$key],
      ];
    }
    $form['branding']['footer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default footer'),
      '#default_value' => $config->get('footer'),
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('html_mail.settings')
      ->set('site_url', rtrim(trim((string) $form_state->getValue('site_url')), '/'))
      ->set('theme', (string) $form_state->getValue('theme'))
      ->set('brand_name', trim((string) $form_state->getValue('brand_name')))
      ->set('logo_url', trim((string) $form_state->getValue('logo_url')))
      ->set('logo_width', (int) $form_state->getValue('logo_width'))
      ->set('font_family', trim((string) $form_state->getValue('font_family')))
      ->set('colors', array_map('strtolower', array_intersect_key((array) $form_state->getValue('colors'), HtmlMailRenderer::DEFAULT_COLORS)))
      ->set('footer', trim((string) $form_state->getValue('footer')))
      ->save();
    parent::submitForm($form, $form_state);
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
