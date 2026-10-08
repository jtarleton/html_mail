<?php

/**
 * @file
 * Post update functions for HTML Mail: Symfony Mailer.
 */

declare(strict_types=1);

/**
 * Adds html_mail_symfony.settings, keeping core's user emails in scope.
 */
function html_mail_symfony_post_update_settings(): void {
  $config = \Drupal::configFactory()->getEditable('html_mail_symfony.settings');
  if ($config->isNew()) {
    $config->set('modules', ['user'])->save(TRUE);
  }
}
