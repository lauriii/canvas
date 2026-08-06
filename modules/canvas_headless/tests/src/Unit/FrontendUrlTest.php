<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\FrontendUrl;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the frontend URL canonicalization.
 */
#[Group('canvas_headless')]
class FrontendUrlTest extends UnitTestCase {

  /**
   * Tests the canonical base URL and origin of accepted values.
   */
  #[DataProvider('providerAccepted')]
  public function testAccepted(string $frontend_url, string $base_url, string $origin): void {
    $frontend = FrontendUrl::fromConfig($frontend_url);
    self::assertNotNull($frontend);
    self::assertSame($base_url, $frontend->baseUrl);
    self::assertSame($origin, $frontend->origin);
  }

  /**
   * Data provider of accepted URLs and their canonical forms.
   *
   * @return array<string, array{string, string, string}>
   *   URL, expected canonical base URL, expected origin.
   */
  public static function providerAccepted(): array {
    return [
      'plain https' => ['https://example.com', 'https://example.com', 'https://example.com'],
      'with base path' => ['https://example.com/app', 'https://example.com/app', 'https://example.com'],
      'explicit non-default port' => ['http://localhost:3000', 'http://localhost:3000', 'http://localhost:3000'],
      'port and path' => ['http://localhost:3000/app', 'http://localhost:3000/app', 'http://localhost:3000'],
      // Browsers omit a scheme-default port from an origin's ASCII
      // serialization, so keeping it would break every postMessage origin
      // comparison. Both derived values drop it, together.
      'scheme-default https port' => ['https://example.com:443', 'https://example.com', 'https://example.com'],
      'scheme-default http port' => ['http://example.com:80', 'http://example.com', 'http://example.com'],
      // The host is normalized, so the origin matches what a browser
      // reports and the base URL addresses the same site.
      'uppercase host' => ['https://Example.COM/app', 'https://example.com/app', 'https://example.com'],
      'uppercase scheme' => ['HTTPS://example.com', 'https://example.com', 'https://example.com'],
      // A canonical dotted quad survives a browser's host parser unchanged.
      'canonical IPv4' => ['http://127.0.0.1:3000', 'http://127.0.0.1:3000', 'http://127.0.0.1:3000'],
      // An internationalized name in its ASCII form: exactly the string a
      // MessageEvent reports.
      'ascii idn host' => ['https://xn--xample-9ua.com', 'https://xn--xample-9ua.com', 'https://xn--xample-9ua.com'],
    ];
  }

  /**
   * Tests that ambiguous or non-web URLs are refused.
   */
  #[DataProvider('providerRefused')]
  public function testRefused(string $frontend_url): void {
    self::assertNull(FrontendUrl::fromConfig($frontend_url));
  }

  /**
   * Data provider of URLs that must be refused.
   *
   * @return array<string, array{string}>
   *   The URL to refuse.
   */
  public static function providerRefused(): array {
    return [
      // The parser differential: PHP's parse_url reads the host as
      // trusted.example (everything before the backslash being userinfo),
      // while a browser folds the backslash to a slash and loads
      // evil.test — which would receive the iframe URL's activation
      // assertion. Neither reading is trusted; the value is refused.
      'backslash before credentials' => ['https://evil.test\\@trusted.example'],
      'backslash in authority' => ['https://evil.test\\trusted.example'],
      'embedded credentials' => ['https://evil.test@trusted.example'],
      'credentials with password' => ['https://user:pass@example.com'],
      'query string' => ['https://example.com?assertion=x'],
      'fragment' => ['https://example.com#x'],
      'literal dot segment' => ['https://example.com/./app'],
      'literal dot-dot segment' => ['https://example.com/a/../app'],
      'encoded dot segment' => ['https://example.com/%2e/app'],
      'encoded dot-dot segment' => ['https://example.com/a/%2E%2e/app'],
      'mixed encoded dot-dot segment' => ['https://example.com/a/.%2e/app'],
      'whitespace' => ['https://example .com'],
      'javascript scheme' => ['javascript:parent.alert(document.domain)#x'],
      'data scheme' => ['data:text/html,x'],
      'file scheme' => ['file:///etc/passwd'],
      'no scheme' => ['example.com'],
      'protocol-relative' => ['//example.com'],
      'no host' => ['https://'],
      'trailing slash' => ['https://example.com/'],
      'trailing slash after path' => ['https://example.com/app/'],
      'empty' => [''],
      // Host spellings a browser rewrites before forming an origin. PHP
      // keeps them verbatim, so accepting any of them would navigate the
      // iframe to one origin while postMessage compared against another.
      'percent-encoded host' => ['https://%65xample.com'],
      'hex IPv4' => ['https://0x7f000001'],
      'decimal IPv4' => ['https://2130706433'],
      'octal IPv4' => ['https://0177.0.0.1'],
      'short-form IPv4' => ['https://127.1'],
      'out-of-range IPv4' => ['https://999.0.0.1'],
      'leading-zero IPv4' => ['https://01.0.0.1'],
      'non-ASCII IDN' => ['https://éxample.com'],
      'trailing dot' => ['https://example.com.'],
      'IPv6 literal' => ['https://[::1]'],
      'numeric final label' => ['https://example.123'],
    ];
  }

}
