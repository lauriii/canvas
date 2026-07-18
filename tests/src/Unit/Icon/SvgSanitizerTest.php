<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Icon;

use Drupal\canvas\Icon\SvgSanitizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the SVG upload trust boundary.
 */
#[CoversClass(SvgSanitizer::class)]
#[Group('canvas')]
final class SvgSanitizerTest extends UnitTestCase {

  #[DataProvider('providerBenignSvg')]
  public function testBenignSvgPasses(string $svg): void {
    self::assertSame([], SvgSanitizer::validate($svg));
  }

  public static function providerBenignSvg(): array {
    $star = \file_get_contents(__DIR__ . '/../../../modules/canvas_test_icons/icons/star.svg');
    self::assertIsString($star);
    return [
      'real icon from the test icon pack' => [$star],
      'local fragment references are allowed' => [
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24"><defs><linearGradient id="g"/></defs><rect fill="url(#g)"/><use xlink:href="#g"/></svg>',
      ],
      'animation of non-reference attributes is allowed' => [
        '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"><animate attributeName="opacity" values="0;1" dur="1s"/></circle></svg>',
      ],
    ];
  }

  #[DataProvider('providerMaliciousSvg')]
  public function testMaliciousSvgIsRejected(string $svg): void {
    self::assertNotSame([], SvgSanitizer::validate($svg));
  }

  public static function providerMaliciousSvg(): array {
    return [
      'not well-formed XML' => ['<svg><'],
      'root element is not svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"></html>'],
      'script element' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
      'script element with mixed case' => ['<svg xmlns="http://www.w3.org/2000/svg"><ScRiPt>alert(1)</ScRiPt></svg>'],
      'foreignObject element' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>x</div></foreignObject></svg>'],
      'iframe element' => ['<svg xmlns="http://www.w3.org/2000/svg"><iframe src="#x"/></svg>'],
      'embed element' => ['<svg xmlns="http://www.w3.org/2000/svg"><embed src="#x"/></svg>'],
      'object element' => ['<svg xmlns="http://www.w3.org/2000/svg"><object data="x"/></svg>'],
      'onload attribute' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
      'onclick attribute on nested element' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)"/></svg>'],
      'DOCTYPE with external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'],
      'plain DOCTYPE' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>'],
      'processing instruction' => ['<svg xmlns="http://www.w3.org/2000/svg"><?php echo 1; ?></svg>'],
      'xml-stylesheet processing instruction' => ['<?xml-stylesheet href="http://evil.example/x.css"?><svg xmlns="http://www.w3.org/2000/svg"/>'],
      'javascript: href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>x</text></a></svg>'],
      'javascript: href with embedded whitespace' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="java script:alert(1)"><text>x</text></a></svg>'],
      'data:text/html href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="data:text/html;base64,PHNjcmlwdD4="><text>x</text></a></svg>'],
      'external image href' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>'],
      'external xlink:href' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.example/sprite.svg#x"/></svg>'],
      'protocol-relative href' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="//evil.example/x.png"/></svg>'],
      'relative path href' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="../secret.svg"/></svg>'],
      'animate targeting href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="#x"><animate attributeName="href" values="#y"/></a></svg>'],
      'set targeting xlink:href' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="#x"><set attributeName="xlink:href" to="#y"/></a></svg>'],
      'style element with external url()' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: url(http://evil.example/f.svg#a); }</style><rect/></svg>'],
      'style element with @import' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>@import "http://evil.example/x.css";</style></svg>'],
      'style attribute with external url()' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill: url(\'https://evil.example/f.svg#a\')"/></svg>'],
      'style attribute with expression()' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect style="width: expression(alert(1))"/></svg>'],
      'style element with CSS-escaped @import' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>@\69 mport "http://evil.example/x.css";</style></svg>'],
      'style element with CSS-escaped url()' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: \75rl(http://evil.example/f.svg#a); }</style><rect/></svg>'],
      'style attribute with backslash-escaped expression()' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect style="width: e\xpression(alert(1))"/></svg>'],
    ];
  }

}
