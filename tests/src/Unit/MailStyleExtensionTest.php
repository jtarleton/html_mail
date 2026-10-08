<?php

declare(strict_types=1);

namespace Drupal\Tests\html_mail\Unit;

use Drupal\html_mail\Twig\MailStyleExtension;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the |mail_style Twig filter.
 *
 * @group html_mail
 */
#[CoversClass(MailStyleExtension::class)]
#[Group('html_mail')]
class MailStyleExtensionTest extends UnitTestCase {

  /**
   * Adds inline styles to the named tags only.
   */
  public function testStyle(): void {
    $extension = new MailStyleExtension();
    $html = '<p>One <a href="https://example.com">link</a></p><p style="color:red">Two</p><pre>x</pre>';
    $styled = (string) $extension->style($html, ['p' => 'margin:0;', 'a' => 'color:"#06c";']);
    $this->assertSame('<p style="margin:0;">One <a style="color:&quot;#06c&quot;;" href="https://example.com">link</a></p><p style="color:red">Two</p><pre>x</pre>', $styled);
    $this->assertSame('', $extension->style('', ['p' => 'margin:0;']));
  }

}
