<?php

/**
 * @file
 * Post update functions for HTML Mail.
 */

declare(strict_types=1);

use Drupal\html_mail\HtmlMailRenderer;

/**
 * Adds the theme, branding and color settings with their defaults.
 */
function html_mail_post_update_branding_settings(): void {
  $config = \Drupal::configFactory()->getEditable('html_mail.settings');
  $defaults = [
    'site_url' => '',
    'theme' => '',
    'brand_name' => '',
    'logo_url' => '',
    'logo_width' => 160,
    'font_family' => HtmlMailRenderer::DEFAULT_FONT,
    'colors' => HtmlMailRenderer::DEFAULT_COLORS,
    'footer' => '',
  ];
  foreach ($defaults as $key => $value) {
    if ($config->get($key) === NULL) {
      $config->set($key, $value);
    }
  }
  $config->save(TRUE);
}
