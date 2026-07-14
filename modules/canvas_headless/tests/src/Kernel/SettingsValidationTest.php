<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation of the canvas_headless.settings config schema.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
class SettingsValidationTest extends CanvasKernelTestBase {

  /**
   * The frontend URL constraint's violation message.
   */
  private const MESSAGE = 'The frontend URL must be an absolute http:// or https:// URL whose host is a hostname or a dotted-quad IPv4 address, with no credentials, query, fragment, or trailing slash.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * Tests that the shipped defaults produce no violations.
   */
  public function testShippedDefaultsValidate(): void {
    $this->installConfig(['canvas_headless']);
    $this->assertSame([], $this->validate($this->config('canvas_headless.settings')->getRawData()));
  }

  /**
   * Tests that the constraints reject invalid values.
   */
  public function testConstraintsRejectInvalidValues(): void {
    $violations = $this->validate([
      'frontend_url' => 'http://localhost:3000/',
      'component_metadata_url' => 'component-metadata.json',
      'draft_path' => 'api/draft',
      'assertion_expiration' => 3600,
    ]);

    $this->assertSame([
      'assertion_expiration' => ['This value should be between <em class="placeholder">10</em> and <em class="placeholder">300</em>.'],
      'component_metadata_url' => ['The component metadata URL must be an absolute path or an HTTP(S) URL.'],
      'draft_path' => ['The draft path must begin with a slash.'],
      'frontend_url' => [self::MESSAGE],
    ], $violations);
  }

  /**
   * Tests that only unambiguous absolute http(s) URLs are accepted.
   *
   * The value is assigned to the editor frame's iframe src, which starts
   * out same-origin and carries the activation assertion in its query
   * string. An executable scheme would run script in the Drupal origin; a
   * value whose host PHP and the browser read differently would send the
   * assertion to the browser's reading. Syntactically valid URIs are not
   * enough.
   */
  #[DataProvider('providerFrontendUrls')]
  public function testFrontendUrlRestriction(string $frontend_url, bool $valid): void {
    $violations = $this->validate([
      'frontend_url' => $frontend_url,
      'draft_path' => '/api/draft',
      'assertion_expiration' => 60,
    ]);

    if ($valid) {
      $this->assertSame([], $violations);
    }
    else {
      // Some cases additionally violate the uri primitive type; the
      // restriction must reject them all regardless.
      $this->assertSame(['frontend_url'], \array_keys($violations));
      $this->assertContains(self::MESSAGE, $violations['frontend_url']);
    }
  }

  /**
   * Data provider of frontend URL cases.
   *
   * @return array<string, array{string, bool}>
   *   URL and whether it must validate.
   */
  public static function providerFrontendUrls(): array {
    return [
      'plain https' => ['https://example.com', TRUE],
      'http with port' => ['http://localhost:3000', TRUE],
      'with base path' => ['https://example.com/app', TRUE],
      'canonical IPv4' => ['http://127.0.0.1:3000', TRUE],
      'punycode host' => ['https://xn--xample-9ua.com', TRUE],
      // Host spellings browsers rewrite: the schema must refuse exactly what
      // FrontendUrl refuses, or a value could be saved that fails closed at
      // runtime. See FrontendUrlTest for the same matrix.
      'percent-encoded host' => ['https://%65xample.com', FALSE],
      'hex IPv4' => ['https://0x7f000001', FALSE],
      'decimal IPv4' => ['https://2130706433', FALSE],
      'octal IPv4' => ['https://0177.0.0.1', FALSE],
      'short-form IPv4' => ['https://127.1', FALSE],
      'out-of-range IPv4' => ['https://999.0.0.1', FALSE],
      'non-ASCII IDN' => ['https://éxample.com', FALSE],
      'IPv6 literal' => ['https://[::1]', FALSE],
      // The parser differential. PHP's parse_url reports the host as
      // trusted.example; a browser folds the backslash to a slash and loads
      // evil.test, which would then receive the iframe URL's activation
      // assertion. A host allowlist alone would pass this value.
      'backslash before credentials' => ['https://evil.test\\@trusted.example', FALSE],
      'backslash in authority' => ['https://evil.test\\trusted.example', FALSE],
      'embedded credentials' => ['https://evil.test@trusted.example', FALSE],
      'query string' => ['https://example.com?assertion=x', FALSE],
      'fragment' => ['https://example.com#x', FALSE],
      'javascript scheme' => ['javascript:parent.alert(document.domain)#x', FALSE],
      'data scheme' => ['data:text/html,<script>parent.alert(1)</script>', FALSE],
      'scheme only, no host' => ['https://', FALSE],
      'protocol-relative' => ['//example.com', FALSE],
      'trailing slash' => ['https://example.com/', FALSE],
      'trailing slash after path' => ['https://example.com/app/', FALSE],
    ];
  }

  /**
   * Tests that required keys are present and no other key is allowed.
   */
  public function testUnknownAndMissingKeysAreRejected(): void {
    $violations = $this->validate([
      'frontend_url' => 'http://localhost:3000',
      'draft_path' => '/api/draft',
      'unknown_key' => 'whatever',
    ]);

    $this->assertSame([
      '' => ["'assertion_expiration' is a required key."],
      'unknown_key' => ["'unknown_key' is not a supported key."],
    ], $violations);
  }

  /**
   * Validates settings data, returning violation messages by property path.
   *
   * @param array $data
   *   The settings data to validate.
   *
   * @return string[][]
   *   Violation messages keyed by property path, sorted by path.
   */
  private function validate(array $data): array {
    $typed_data = $this->container->get('config.typed')
      ->createFromNameAndData('canvas_headless.settings', $data);
    $violations = [];
    foreach ($typed_data->validate() as $violation) {
      $violations[$violation->getPropertyPath()][] = (string) $violation->getMessage();
    }
    ksort($violations);
    return $violations;
  }

}
