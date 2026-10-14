# Security

## Reporting a vulnerability

Do not report security issues in the public issue queue.

Magic HTML Mail is applying for coverage under the
[Drupal security advisory policy](https://www.drupal.org/drupal-security-team/security-advisory-process-and-permissions-policy)
([application](https://www.drupal.org/project/projectapplications/issues/3630186)).
Once the project page shows the coverage shield, report issues to the Drupal
Security Team as described in
[Reporting a security issue](https://www.drupal.org/docs/develop/issues/issue-procedures-and-etiquette/reporting-a-security-issue).

Until then, contact the maintainer privately through the contact form on
their drupal.org profile, linked from the
[project page](https://www.drupal.org/project/html_mail).

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x (latest release) | Yes |
| Earlier 1.0.x releases | Upgrade to the latest release |

## Security model

Magic HTML Mail adds no public routes and stores no user data. Its single
page, `/admin/config/system/html-mail`, needs the *Administer Magic HTML Mail*
permission, which is marked `restrict access: true`. Give it only to trusted
administrators.

### Trusted input

These are trusted, the same way Drupal trusts theme templates and
administrative configuration:

- **The template.** `html-mail.html.twig` and any theme override of it are
  code. Twig autoescaping applies to every variable, as in any template.
- **Settings** (`html_mail.settings`, `html_mail_symfony.settings`). They are
  edited by administrators or deployed as configuration. The font stack and
  colors are written into inline `style` attributes. Twig escapes them for
  HTML, but they are not parsed as CSS. Colors come from a color field
  (`#rrggbb`).
- **Sending modules' code.** The module renders what `hook_mail()` gives it.
  It does not decide who may send mail or to whom.

### Untrusted input

A sending module may build its message from user input, such as a contact
form, a comment or a username. So the module treats the message body and all
hints as untrusted:

| Input | Handling |
|---|---|
| Plain-text body | `Html::escape()`, then line breaks become `<br>` and `http(s)` URLs become links. Each link's `href` goes through `UrlHelper::stripDangerousProtocols()` and is escaped. |
| `body` and `footer` hints (HTML) | `Xss::filter()` with a short allowlist: `p h2 h3 ul ol li strong em b i a br`. |
| URL hints (`button.url`, `secondary_button.url`, `fallback_url`, `unsubscribe_url`) | `UrlHelper::stripDangerousProtocols()`, then Twig attribute escaping. |
| Text hints (`heading`, `greeting`, `eyebrow`, `preheader`, `code`, `notice`, `callout.*`, button labels) | Twig autoescaping. |
| Default footer setting (HTML) | `Xss::filterAdmin()`. |
| Logo URL setting | `UrlHelper::stripDangerousProtocols()`. |
| Symfony Mailer module list | Only machine names (`[a-z0-9_]+`) and `*` are saved. |

The `|mail_style` Twig filter adds `style` attributes to tags in HTML that is
already safe. It returns markup, so it must never receive raw user input. In
the default template it only receives the filtered `body`, `body_after` and
`footer`. Its CSS values are escaped with `htmlspecialchars()`.

### Links and the site URL

Links and the logo are made absolute from the **Site URL** setting. If that
setting is empty, they use the current request's scheme and host. Without
[`trusted_host_patterns`](https://www.drupal.org/docs/installing-drupal/trusted-host-settings),
a forged `Host` header could put another domain into those links (host header
poisoning). Set the Site URL, or configure `trusted_host_patterns`, on every
site. Drupal's status report warns when the latter is missing.

### Sending

- **Test message.** The settings page can send a test message to any address.
  The button only works for users with the restricted permission, and Form
  API protects it against CSRF. The message content is fixed.
- **Amazon SES submodule.** It passes only `List-Unsubscribe` and
  `List-Unsubscribe-Post` through from the message headers. Symfony Mime builds
  the headers, so values cannot inject new header lines.
- **Symfony Mailer submodule.** It replaces the HTML and text bodies after
  Drupal Symfony Mailer renders them. Addresses, headers and transport stay
  under that module's control.

### Out of scope

- Transport security: SMTP/TLS, API credentials, SPF, DKIM and DMARC. These
  belong to the transport module and the mail provider.
- How email clients render HTML. The module sends no JavaScript and loads no
  remote resources except the configured logo.
- Who may trigger mail, and to which addresses. That is up to the sending
  module.

## Quality checks

Every push to drupal.org runs the GitLab CI templates for contrib projects:
PHPCS (Drupal and DrupalPractice standards), PHPStan level 8 (`phpstan.neon`),
cspell, ESLint, composer validation, and PHPUnit unit, kernel and functional
tests. The tests cover the filtering above, including `javascript:` URLs and
disallowed tags in hints.
