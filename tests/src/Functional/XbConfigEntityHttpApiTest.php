<?php

declare(strict_types=1);

use Drupal\Core\Url;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\system\Entity\Menu;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use GuzzleHttp\RequestOptions;

/**
 * @covers \Drupal\experience_builder\Controller\ApiConfigListController
 * @group experience_builder
 * @internal
 */
class XbConfigEntityHttpApiTest extends BrowserTestBase {

  use ApiRequestTrait;
  use TestDataUtilitiesTrait;
  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'xb_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Ensures the `xb_config_entity_type_id` route requirement does its work.
   */
  public function testNonXbConfigEntity(): void {
    // The System module comes with the Menu config entity, and multiple are
    // created upon installation.
    $this->assertNotEmpty(Menu::loadMultiple());

    // But accessing it results in a 404 HTML response: not a single clue that
    // this is *almost* an HTTP API route.
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/xb/api/config/menu'), []);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type')[0]);

    // Even as a logged in user with all imaginable permissions.
    $this->drupalLogin($this->rootUser);
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/xb/api/config/menu'), []);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type')[0]);
  }

  public function testPageTemplate(): void {
    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri('base:/xb/api/config/page_template');

    // Anonymously: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'message' => "The 'access administration pages' permission is required.",
    ], $body);

    // Authenticated & authorized: 200, but empty list.
    $this->drupalLogin($this->rootUser);
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Create a Page Template via the XB HTTP API, but forget crucial data: 422.
    $page_template_to_send = [
      'theme' => 'stark',
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
        // 🐛 The `content` and `help` regions were forgotten!
      ],
    ];
    $request_options = [
      RequestOptions::JSON => $page_template_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Configuration for the region "<em class="placeholder">content</em>" (<em class="placeholder">content</em>) is missing.',
          'source' => ['pointer' => 'component_trees'],
        ],
        [
          'detail' => 'Configuration for the region "<em class="placeholder">help</em>" (<em class="placeholder">help</em>) is missing.',
          'source' => ['pointer' => 'component_trees'],
        ],
      ],
    ], $body);

    // Add missing crucial data, but still make a mistake: 422.
    $page_template_to_send['component_trees']['content'] = [
      'tree' => self::encodeXBData([
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
        ],
      ]),
    ];
    $request_options = [
      RequestOptions::JSON => $page_template_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Configuration for the region "<em class="placeholder">help</em>" (<em class="placeholder">help</em>) is missing.',
          'source' => ['pointer' => 'component_trees'],
        ],
        [
          'detail' => "'props' is a required key.",
          'source' => ['pointer' => 'component_trees.content'],
        ],
        [
          'detail' => 'The array must contain a "props" key.',
          'source' => ['pointer' => 'component_trees.content'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    // Create a Page Template via the XB HTTP API, correctly: 201.
    $page_template_to_send['component_trees']['help'] = NULL;
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $page_template_to_send['component_trees']['content']['props'] = self::encodeXBData([
      'uuid-in-root' => [
        'heading' => $generate_static_prop_source('world'),
      ],
      'uuid-in-root-another' => [
        'heading' => $generate_static_prop_source('another world'),
      ],
    ]);
    // Note how the key-value pairs under `component_tree` are sorted by key.
    $expected_stark_page_template_normalization = [
      'component_trees' => [
        'breadcrumb' => NULL,
        'content' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
              ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
            ],
          ]),
          'props' => self::encodeXBData([
            'uuid-in-root' => [
              'heading' => $generate_static_prop_source('world'),
            ],
            'uuid-in-root-another' => [
              'heading' => $generate_static_prop_source('another world'),
            ],
          ]),
        ],
        'footer' => NULL,
        'header' => NULL,
        'help' => NULL,
        'highlighted' => NULL,
        'page_bottom' => NULL,
        'page_top' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
      ],
    ];
    $request_options = [
      RequestOptions::JSON => $page_template_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/page_template/stark",
      ],
    ]);
    $this->assertSame($expected_stark_page_template_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "$base/xb/api/config/page_template/stark" => $expected_stark_page_template_normalization,
    ], $body);
    // Use the individual URL in the list response body.
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:' . substr(array_keys($body)[0], strlen($base))), [], 200, ['user.permissions'], ['config:experience_builder.page_template.stark', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame($expected_stark_page_template_normalization, $individual_body);

    // Modify a Page Template incorrectly: 422.
    // Copy the component tree of the `content` region over to the
    // `sidebar_first` region, but then accidentally erase the `tree` half.
    $page_template_to_send['component_trees']['sidebar_first'] = $page_template_to_send['component_trees']['content'];
    $page_template_to_send['component_trees']['sidebar_first']['tree'] = '{}';
    $request_options = [
      RequestOptions::JSON => $page_template_to_send,
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/page_template/stark'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The root UUID is missing.',
          'source' => ['pointer' => 'component_trees.sidebar_first.tree[a548b48d-58a8-4077-aa04-da9405a6f418]'],
        ],
      ],
    ], $body);

    // Modify a Page Template correctly: 200.
    $page_template_to_send['component_trees']['sidebar_first'] = $page_template_to_send['component_trees']['content'];
    $request_options = [
      RequestOptions::JSON => $page_template_to_send,
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/page_template/stark'), $request_options, 200, NULL, NULL, NULL, NULL);
    $expected_stark_page_template_normalization['component_trees']['sidebar_first'] = $expected_stark_page_template_normalization['component_trees']['content'];
    $this->assertSame($expected_stark_page_template_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "$base/xb/api/config/page_template/stark" => $expected_stark_page_template_normalization,
    ], $body);

    // Delete the sole Page Template via the XB HTTP API: 204.
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/page_template/stark'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/config/page_template/stark'), [], 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame([], $individual_body);

    // This was now tested full circle! ✅
  }

  /**
   * @return ?array
   *   The decoded JSON response, or NULL if there is no body.
   *
   * @throws \JsonException
   */
  private function assertExpectedResponse(string $method, Url $url, array $request_options, int $expected_status, ?array $expected_cache_contexts, ?array $expected_cache_tags, ?string $expected_page_cache, ?string $expected_dynamic_page_cache, array $additional_expected_response_headers = []): ?array {
    $response = $this->makeApiRequest($method, $url, $request_options);
    $body = (string) $response->getBody();
    $this->assertSame($expected_status, $response->getStatusCode(), $body);

    // Cacheability headers.
    $this->assertSame($expected_page_cache !== NULL, $response->hasHeader('X-Drupal-Cache'));
    if ($expected_page_cache !== NULL) {
      $this->assertSame($expected_page_cache, $response->getHeader('X-Drupal-Cache')[0], 'Page Cache response header');
    }
    $this->assertSame($expected_dynamic_page_cache !== NULL, $response->hasHeader('X-Drupal-Dynamic-Cache'));
    if ($expected_dynamic_page_cache !== NULL) {
      $this->assertSame($expected_dynamic_page_cache, $response->getHeader('X-Drupal-Dynamic-Cache')[0], 'Dynamic Page Cache response header');
    }
    $this->assertSame($expected_cache_tags !== NULL, $response->hasHeader('X-Drupal-Cache-Tags'));
    if ($expected_cache_tags !== NULL) {
      $this->assertEqualsCanonicalizing($expected_cache_tags, explode(' ', $response->getHeader('X-Drupal-Cache-Tags')[0]));
    }
    $this->assertSame($expected_cache_contexts !== NULL, $response->hasHeader('X-Drupal-Cache-Contexts'));
    if ($expected_cache_contexts !== NULL) {
      $this->assertEqualsCanonicalizing($expected_cache_contexts, explode(' ', $response->getHeader('X-Drupal-Cache-Contexts')[0]));
    }

    // Optionally, additional expected response headers can be validated.
    if ($additional_expected_response_headers) {
      foreach ($additional_expected_response_headers as $header_name => $expected_value) {
        $this->assertSame($response->getHeader($header_name), $expected_value);
      }
    }

    // Response must at least be decodable JSON, let this throw an exception
    // otherwise. (Assertions of the contents happen outside this method.)
    if ($body === '') {
      return NULL;
    }
    $json = json_decode($body, associative: TRUE, flags: JSON_THROW_ON_ERROR);

    return $json;
  }

}
