<?php

declare(strict_types=1);

namespace Drupal\html_mail;

use Drupal\Component\Render\MarkupInterface;
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
 * body as usual and may add hints under $message['html_mail'] (or
 * $params['html_mail']): heading, code, button, callout, notice, footer...
 * (the variables of the html_mail theme hook).
 *
 * Body lines are treated the same way core's PhpMail treats them, i.e. as
 * HTML. That matters because token values come in HTML-escaped, and modules
 * like contact put rendered markup in the body. We run the body through
 * MailFormatHelper::htmlToText() just like core does and use the result as
 * the plain-text part. Unless there's a 'body' hint, the HTML version is
 * built from that same text, one <p> per paragraph, with links made
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
   * The tags kept in an HTML body or footer hint.
   */
  public const ALLOWED_TAGS = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'a', 'br'];

  /**
   * The hints that are URLs.
   */
  protected const URL_HINTS = ['fallback_url', 'unsubscribe_url'];

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
      'logo_url' => $logo !== '' ? UrlHelper::stripDangerousProtocols($logo) : '',
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
    $body = $message['body'] ?? [];
    $text = MailFormatHelper::htmlToText(implode("\n\n", array_map('strval', is_array($body) ? $body : [$body])));
    $paragraphs = $this->paragraphs($text);

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
    foreach (self::URL_HINTS as $key) {
      if (isset($variables[$key])) {
        $variables[$key] = UrlHelper::stripDangerousProtocols($variables[$key]);
      }
    }
    foreach (['button', 'secondary_button', 'callout'] as $key) {
      if (!empty($hints[$key]) && is_array($hints[$key])) {
        $variables[$key] = $hints[$key];
      }
    }
    foreach (['button', 'secondary_button'] as $key) {
      if (isset($variables[$key]['url'])) {
        $variables[$key]['url'] = UrlHelper::stripDangerousProtocols((string) $variables[$key]['url']);
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
      $buttonUrl = isset($hints['button']['url']) ? (string) $hints['button']['url'] : NULL;
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
        $html[$part] .= '<p>' . nl2br($this->linkify($trimmed), FALSE) . '</p>';
      }
      $variables['body'] = Markup::create($html[0]);
      $variables['body_after'] = Markup::create($html[1]);
    }
    if (empty($variables['preheader'])) {
      $variables['preheader'] = $this->preheader((string) $variables['body']);
    }

    $build = ['#theme' => 'html_mail'];
    foreach ($variables as $key => $value) {
      $build['#' . $key] = $value;
    }
    return ['html' => $this->renderInMailTheme($build), 'text' => $text];
  }

  /**
   * Splits the plain-text body into paragraphs.
   *
   * @param string $text
   *   Output from MailFormatHelper::htmlToText(). It's wrapped using
   *   format=flowed, where a trailing space means the line continues on the
   *   next one. We join those back up, but leave the "-- " signature marker
   *   alone.
   */
  protected function paragraphs(string $text): array {
    $text = (string) preg_replace('/(?<!^--) +\n/m', ' ', str_replace("\r\n", "\n", $text));
    $out = [];
    foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
      if (trim($paragraph) !== '') {
        $out[] = rtrim($paragraph);
      }
    }
    return $out;
  }

  /**
   * Escapes plain text and turns http(s) URLs into links.
   *
   * This works on the raw text and escapes afterwards. Matching URLs in
   * already-escaped text would pull entities like &gt; into the link.
   */
  protected function linkify(string $text): string {
    $parts = preg_split('#(\bhttps?://[^\s<>"]*[^\s<>".,;:!?)\]\'])#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $html = '';
    foreach ($parts as $i => $part) {
      $escaped = Html::escape($part);
      $html .= $i % 2 ? '<a href="' . Html::escape(UrlHelper::stripDangerousProtocols($part)) . '">' . $escaped . '</a>' : $escaped;
    }
    return $html;
  }

  /**
   * Builds the inbox preview text from the start of the body.
   *
   * Twig escapes this when printing it, so entities are decoded here to avoid
   * double escaping. Tags get a space in front so paragraphs don't run
   * together once they're stripped.
   */
  protected function preheader(string $html): string {
    $text = Html::decodeEntities(strip_tags(str_replace('<', ' <', $html)));
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 140);
  }

  /**
   * A hint given as HTML, filtered down to the allowed tags.
   *
   * Hints usually come from code, but a sending module may build them from
   * user input (a contact form, a comment), so they are never trusted as is.
   */
  protected function markup(mixed $value): MarkupInterface|string {
    return Markup::create(Xss::filter((string) $value, self::ALLOWED_TAGS));
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
