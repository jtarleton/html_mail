<?php

declare(strict_types=1);

namespace Drupal\html_mail;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the HTML and plain-text versions of an outgoing message.
 *
 * A sending module needs nothing from this module: its hook_mail() writes the
 * plain-text body as usual and may add hints under $message['html_mail'] (or
 * $params['html_mail']): heading, code, button, callout, notice, footer...
 * (the variables of the html_mail theme hook). Without hints, the plain-text
 * body becomes the HTML body, paragraph by paragraph, with links made
 * clickable.
 *
 * The template is rendered in the theme set in html_mail.settings:theme (the
 * default theme when empty), whatever theme is active (an admin page, drush,
 * cron), so a theme can restyle every email by overriding
 * html-mail.html.twig. Branding (name, logo, colors, font, footer) comes from
 * html_mail.settings and reaches the template as variables.
 */
class HtmlMailRenderer {

  public function __construct(
    protected RendererInterface $renderer,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
    protected ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * The default template's colors, for settings saved without them.
   */
  public const DEFAULT_COLORS = [
    'text' => '#1f2328',
    'muted' => '#59636e',
    'accent' => '#0b5cad',
    'accent_text' => '#ffffff',
    'background' => '#f3f4f6',
    'surface' => '#ffffff',
    'panel' => '#f6f8fa',
    'border' => '#e5e7eb',
    'badge' => '#1a7f37',
  ];

  /**
   * The default template's font stack.
   */
  public const DEFAULT_FONT = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

  /**
   * The absolute base URL for links and images.
   */
  public function siteUrl(): string {
    $url = (string) $this->settings()->get('site_url');
    if ($url === '' && ($request = $this->requestStack->getCurrentRequest())) {
      $url = $request->getSchemeAndHttpHost() . $request->getBasePath();
    }
    return rtrim($url, '/');
  }

  /**
   * The theme that renders the template.
   */
  public function themeName(): string {
    $theme = (string) $this->settings()->get('theme');
    if ($theme === '' || !$this->themeHandler->themeExists($theme)) {
      $theme = (string) $this->configFactory->get('system.theme')->get('default');
    }
    return $theme;
  }

  /**
   * The branding variables every email gets: name, logo and style.
   *
   * @return array
   *   site_name, logo_url (absolute), logo_width, and style: the font stack
   *   and the colors (text, muted, accent...).
   */
  public function branding(): array {
    $settings = $this->settings();
    $name = trim((string) $settings->get('brand_name'));
    $logo = trim((string) $settings->get('logo_url'));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
      $logo = $this->siteUrl() . '/' . ltrim($logo, '/');
    }
    return [
      'site_name' => $name !== '' ? $name : (string) $this->configFactory->get('system.site')->get('name'),
      'logo_url' => $logo,
      'logo_width' => (int) ($settings->get('logo_width') ?: 160),
      'style' => [
        'font' => (string) ($settings->get('font_family') ?: self::DEFAULT_FONT),
      ] + array_filter((array) $settings->get('colors')) + self::DEFAULT_COLORS,
    ];
  }

  /**
   * The module's settings.
   */
  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('html_mail.settings');
  }

  /**
   * Builds a message's HTML and plain text.
   *
   * @param array $message
   *   The message as hook_mail() left it (subject, body, langcode, and the
   *   optional 'html_mail' hints).
   *
   * @return array
   *   'html': the rendered template; 'text': the plain-text alternative.
   */
  public function build(array $message): array {
    $hints = ($message['html_mail'] ?? []) + ($message['params']['html_mail'] ?? []);
    $paragraphs = $this->paragraphs($message['body'] ?? []);
    $text = MailFormatHelper::wrapMail(implode("\n\n", $paragraphs));

    $variables = [
      'subject' => (string) ($message['subject'] ?? ''),
      'langcode' => (string) ($message['langcode'] ?? 'en'),
      'site_url' => $this->siteUrl(),
    ] + $this->branding();
    foreach (['preheader', 'eyebrow', 'greeting', 'heading', 'code', 'fallback_url', 'notice', 'unsubscribe_url'] as $key) {
      if (isset($hints[$key]) && $hints[$key] !== '') {
        $variables[$key] = (string) $hints[$key];
      }
    }
    foreach (['button', 'secondary_button', 'callout'] as $key) {
      if (!empty($hints[$key]) && is_array($hints[$key])) {
        $variables[$key] = $hints[$key];
      }
    }
    if (isset($hints['footer'])) {
      $variables['footer'] = $this->markup($hints['footer']);
    }
    elseif (($footer = trim((string) $this->settings()->get('footer'))) !== '') {
      $variables['footer'] = Markup::create(Xss::filterAdmin($footer));
    }

    // The body: given as HTML, or made from the plain text. Paragraphs that
    // only repeat the button's link or the code are left out of the HTML;
    // the template shows those itself.
    if (isset($hints['body'])) {
      $variables['body'] = $this->markup($hints['body']);
    }
    else {
      // Where the plain text has the button's link on a line of its own, the
      // button goes there: the text before it is 'body', after it
      // 'body_after'.
      $buttonUrl = $hints['button']['url'] ?? NULL;
      $skip = array_filter([$hints['fallback_url'] ?? NULL]);
      $html = ['', ''];
      $part = 0;
      foreach ($paragraphs as $paragraph) {
        $trimmed = trim($paragraph);
        if ($buttonUrl !== NULL && $trimmed === $buttonUrl) {
          $part = 1;
          continue;
        }
        if (in_array($trimmed, $skip, TRUE)
          || (!empty($variables['code']) && str_contains($trimmed, $variables['code']))) {
          continue;
        }
        $html[$part] .= '<p>' . $this->linkify(nl2br(Html::escape($trimmed), FALSE)) . '</p>';
      }
      $variables['body'] = Markup::create($html[0]);
      $variables['body_after'] = Markup::create($html[1]);
    }
    if (empty($variables['preheader'])) {
      $variables['preheader'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $variables['body']))), 0, 140);
    }

    $build = ['#theme' => 'html_mail'];
    foreach ($variables as $key => $value) {
      $build['#' . $key] = $value;
    }
    return ['html' => $this->renderInMailTheme($build), 'text' => $text];
  }

  /**
   * The body as a list of plain-text paragraphs.
   */
  protected function paragraphs($body): array {
    $body = is_array($body) ? $body : [$body];
    $out = [];
    foreach ($body as $part) {
      foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", (string) $part)) ?: [] as $paragraph) {
        if (trim($paragraph) !== '') {
          $out[] = rtrim($paragraph);
        }
      }
    }
    return $out;
  }

  /**
   * Makes the http(s) URLs in escaped text clickable.
   */
  protected function linkify(string $escaped): string {
    return (string) preg_replace_callback('#\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]#u', static function (array $m): string {
      $url = Html::decodeEntities($m[0]);
      return '<a href="' . Html::escape(UrlHelper::stripDangerousProtocols($url)) . '">' . $m[0] . '</a>';
    }, $escaped);
  }

  /**
   * A hint given as HTML: trusted (it comes from code), passed through as is.
   */
  protected function markup($value): Markup {
    return Markup::create((string) $value);
  }

  /**
   * Renders in the mail theme.
   *
   * So the theme's html-mail.html.twig applies even from an admin page, drush
   * or cron.
   */
  protected function renderInMailTheme(array $build): string {
    $theme = $this->themeName();
    $previous = $this->themeManager->getActiveTheme();
    $switch = $theme !== '' && $previous->getName() !== $theme;
    if ($switch) {
      $this->themeManager->setActiveTheme($this->themeInitialization->getActiveThemeByName($theme));
    }
    try {
      return (string) $this->renderer->renderInIsolation($build);
    }
    finally {
      if ($switch) {
        $this->themeManager->setActiveTheme($previous);
      }
    }
  }

}
