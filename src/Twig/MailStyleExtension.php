<?php

declare(strict_types=1);

namespace Drupal\html_mail\Twig;

use Drupal\Core\Render\Markup;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The |mail_style filter: inline styles for plain HTML tags.
 *
 * Email clients ignore most stylesheets, so a template gives each tag in the
 * body its style inline:
 *
 * @code
 * {{ body|mail_style({p: 'margin:0 0 12px;', a: 'color:#06c;'}) }}
 * @endcode
 *
 * Tags that already carry a style attribute are left alone.
 */
class MailStyleExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter('mail_style', [$this, 'style'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Adds style="" to the opening tags named in $styles.
   *
   * @param mixed $html
   *   Safe HTML (a Markup object or a string the caller trusts).
   * @param array $styles
   *   Tag name => inline CSS.
   *
   * @return \Drupal\Core\Render\Markup|string
   *   Markup; an empty string stays a string (Markup::create('') returns '').
   */
  public function style($html, array $styles): Markup|string {
    $html = (string) $html;
    if ($html === '') {
      return '';
    }
    foreach ($styles as $tag => $css) {
      $tag = preg_quote(strtolower((string) $tag), '/');
      $css = htmlspecialchars((string) $css, ENT_QUOTES);
      $html = (string) preg_replace_callback('/<' . $tag . '(\s[^>]*)?>/i', static function (array $m) use ($tag, $css): string {
        $attributes = $m[1] ?? '';
        if (stripos($attributes, 'style=') !== FALSE) {
          return $m[0];
        }
        return '<' . stripslashes($tag) . ' style="' . $css . '"' . $attributes . '>';
      }, $html);
    }
    return Markup::create($html);
  }

}
