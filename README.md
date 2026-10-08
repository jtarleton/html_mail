# HTML Mail

Sends Drupal email as HTML from **one themeable template**, with a plain-text
alternative. Any module's `hook_mail()` message gets a clean, responsive HTML
version with no changes to that module. Optional hints let a sending module
add a heading, a one-time code, a button or a summary card.

- Project page: https://www.drupal.org/project/html_mail
- Issues: https://www.drupal.org/project/issues/html_mail

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [For sending modules](#for-sending-modules)
- [For themes](#for-themes)

## Requirements

Drupal 10.3 or 11. A transport, through one of the submodules:

- **HTML Mail: Symfony Mailer** (`html_mail_symfony`) needs
  [Drupal Symfony Mailer](https://www.drupal.org/project/symfony_mailer) 1.5+
  set to replace the mail manager (in 2.x, enable its `mailer_override`
  submodule).
- **HTML Mail: Amazon SES** (`html_mail_ses`) needs
  [Amazon SES](https://www.drupal.org/project/amazon_ses).

## Installation

```sh
composer require drupal/html_mail
drush en html_mail html_mail_symfony   # or html_mail_ses
```

See [Installing Drupal modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Configuration

Go to **Configuration » System » HTML Mail**
(`/admin/config/system/html-mail`, permission *Administer HTML Mail*):

| Setting | What it does |
|---|---|
| Site URL | Absolute base URL for links and images. Set it if mail is sent from cron or drush, where there is no real host (or give drush the URL, e.g. `DRUSH_OPTIONS_URI`, which also fixes links that the sending module builds itself). |
| Mail theme | The theme whose `html-mail.html.twig` renders every email. Default: the site's default theme. |
| Name, logo, logo width | The email header. The name defaults to the site name; the logo is an absolute URL or a path on the site URL (use PNG or JPEG). |
| Font stack, colors | The default template's look. |
| Default footer | HTML for the footer of messages that do not set their own. |
| Send a test message | Sends a message that uses every part of the template. |

Then pick the transport:

- **Symfony Mailer.** It ignores `system.mail:interface`, so there is nothing
  to route. Every message with `html_mail` hints gets the template, plus the
  messages of the modules listed under *Modules whose email always uses the
  template* (default: `user`, core's account emails; `*` for all). Other
  messages keep Symfony Mailer's own wrapping.
- **Amazon SES.** Route mail to the `html_mail_ses` plugin in
  `system.mail:interface`, per module or per message, e.g.
  `drush cset system.mail interface.mymodule html_mail_ses` (or
  `interface.default` for everything). `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers set in `hook_mail()` are passed through.

All settings are in `html_mail.settings` (and `html_mail_symfony.settings`),
so they can be exported with the rest of the site's configuration or
overridden in `settings.php`.

## For sending modules

Nothing to depend on. Write `hook_mail()` as usual: the plain-text body is the
text part, and becomes the HTML body paragraph by paragraph (links made
clickable). Optionally add hints under `$message['html_mail']` (or
`$params['html_mail']`); without this module they are simply ignored:

| Hint | Shown as |
|---|---|
| `heading`, `eyebrow`, `greeting`, `preheader` | title, small line above, greeting, inbox preview |
| `code` | a one-time code, large (paragraphs repeating it are left out of the HTML) |
| `button`, `secondary_button` | `['label' => ..., 'url' => ...]`; the button goes where its URL stood on its own line in the text |
| `fallback_url` | "Button not working? Copy this link" |
| `callout` | a summary card: `eyebrow`, `label`, `value`, `note`, `rows` (`label`, `value`, `badge`, `note`) |
| `body` | the HTML body itself (plain tags: p, h2, h3, ul, ol, li, strong, em, a) instead of the text |
| `notice`, `footer`, `unsubscribe_url` | small print, why the email was sent (HTML), an unsubscribe link |

```php
function mymodule_mail($key, &$message, $params) {
  $url = $params['url'];
  $message['subject'] = t('Confirm your email');
  $message['body'][] = t('Click the link below to confirm your address.');
  $message['body'][] = $url;
  $message['html_mail'] = [
    'heading' => t('Confirm your email'),
    'button' => ['label' => t('Confirm'), 'url' => $url],
    'fallback_url' => $url,
  ];
}
```

## For themes

The settings page covers name, logo, colors and font. To change the layout,
copy `templates/html-mail.html.twig` into the theme (or the mail theme chosen
in the settings) and edit it. Styles must be inline (email clients ignore most
CSS); style the body's plain tags with the
`|mail_style({p: '...', a: '...'})` filter. The settings arrive as the
`site_name`, `logo_url`, `logo_width` and `style` variables (`style.accent`,
`style.text`, `style.font`...). Add more in `THEME_preprocess_html_mail()`.

The template is always rendered in the mail theme, even when mail is sent
from an admin page, drush or cron, so the override applies everywhere.
