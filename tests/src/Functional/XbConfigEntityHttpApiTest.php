<?php

declare(strict_types=1);

use Drupal\Core\Url;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\system\Entity\Menu;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\user\UserInterface;
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
    'block',
    'experience_builder',
    'xb_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser(['access administration pages']);
    assert($user instanceof UserInterface);
    $this->httpApiUser = $user;
  }

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

    // Even as a logged in user with correct permission.
    $this->drupalLogin($this->httpApiUser);
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/xb/api/config/menu'), []);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type')[0]);
  }

  /**
   * @see \Drupal\experience_builder\Entity\PageTemplate
   */
  public function testPageTemplate(): void {
    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri('base:/xb/api/config/page_template');

    // Anonymously: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'message' => "The 'access administration pages' permission is required.",
    ], $body);

    // Authenticated & authorized: 200, but empty list.
    $this->drupalLogin($this->httpApiUser);
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:page_template_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Create a Page Template via the XB HTTP API, but forget crucial data: 422.
    $page_template_to_send = [
      'theme' => 'stark',
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
        'header' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
        // 🐛 The `content` and `help` regions were forgotten!
      ],
      'editable' => [
        'sidebar_first' => FALSE,
        'sidebar_second' => FALSE,
        'header' => FALSE,
        'primary_menu' => TRUE,
        'secondary_menu' => TRUE,
        'footer' => TRUE,
        'page_top' => TRUE,
        'page_bottom' => TRUE,
        'breadcrumb' => TRUE,
        'content' => TRUE,
        'help' => TRUE,
        // 🐛 The `highlighted` regions was forgotten!
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
        [
          'detail' => 'Configuration for the region "<em class="placeholder">highlighted</em>" (<em class="placeholder">highlighted</em>) is missing.',
          'source' => ['pointer' => 'editable'],
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
    $page_template_to_send['editable']['highlighted'] = FALSE;
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
        'header' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
              ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
            ],
          ]),
          'props' => '{}',
        ],
        'help' => NULL,
        'highlighted' => NULL,
        'page_bottom' => NULL,
        'page_top' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
      ],
      'editable' => [
        'breadcrumb' => TRUE,
        'content' => TRUE,
        'footer' => TRUE,
        'header' => FALSE,
        'help' => TRUE,
        'highlighted' => FALSE,
        'page_bottom' => TRUE,
        'page_top' => TRUE,
        'primary_menu' => TRUE,
        'secondary_menu' => TRUE,
        'sidebar_first' => FALSE,
        'sidebar_second' => FALSE,
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
   * @see \Drupal\experience_builder\Entity\Pattern
   */
  public function testPattern(): void {
    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri('base:/xb/api/config/pattern');

    // Anonymously: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'message' => "The 'access administration pages' permission is required.",
    ], $body);

    // Authenticated & authorized: 200, but empty list.
    $this->drupalLogin($this->httpApiUser);
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Create a Pattern via the XB HTTP API, but forget crucial data: 422.
    $pattern_to_send = [
      'id' => 'test',
      'label' => 'Test pattern, please ignore',
      'component_tree' => NULL,
    ];
    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'This value should not be null.',
          'source' => ['pointer' => 'component_tree'],
        ],
      ],
    ], $body);

    // Add missing crucial data, but still make a mistake: 422.
    $pattern_to_send['component_tree']['tree'] = self::encodeXBData([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
        ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
      ],
    ]);
    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => '\'props\' is a required key.',
          'source' => ['pointer' => 'component_tree'],
        ],
        [
          'detail' => 'The array must contain a "props" key.',
          'source' => ['pointer' => 'component_tree'],
        ],
      ],
    ], $body);

    // Add missing crucial data, but use disallowed component blocks: 422.
    $pattern_to_send['component_tree']['tree'] = self::encodeXBData([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
        ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
        ['uuid' => 'uuid-messages', 'component' => 'block.system_messages_block'],
      ],
    ]);
    $pattern_to_send['component_tree']['props'] = self::encodeXBData([]);

    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The \'Drupal\Core\Block\MainContentBlockPluginInterface\' component interface must be absent.',
          'source' => ['pointer' => 'component_tree'],
        ],
        [
          'detail' => 'The \'Drupal\Core\Block\MessagesBlockPluginInterface\' component interface must be absent.',
          'source' => ['pointer' => 'component_tree'],
        ],
        [
          'detail' => 'The \'Drupal\Core\Block\TitleBlockPluginInterface\' component interface must be absent.',
          'source' => ['pointer' => 'component_tree'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    // Create a Pattern via the XB HTTP API, correctly: 201.
    $pattern_to_send['component_tree']['tree'] = self::encodeXBData([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
        ['uuid' => 'uuid-in-root-another', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
      ],
    ]);
    $pattern_to_send['component_tree']['props'] = [];
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $pattern_to_send['component_tree']['props'] = self::encodeXBData([
      'uuid-in-root' => [
        'heading' => $generate_static_prop_source('world'),
      ],
      'uuid-in-root-another' => [
        'heading' => $generate_static_prop_source('another world'),
      ],
    ]);
    // Note how the key-value pairs under `component_tree` are sorted by key.
    $expected_pattern_normalization = [
      'label' => 'Test pattern, please ignore',
      'component_tree' => [
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
    ];
    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/pattern/test",
      ],
    ]);
    $this->assertSame($expected_pattern_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "$base/xb/api/config/pattern/test" => $expected_pattern_normalization,
    ], $body);
    // Use the individual URL in the list response body.
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:' . substr(array_keys($body)[0], strlen($base))), [], 200, ['user.permissions'], ['config:experience_builder.pattern.test', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame($expected_pattern_normalization, $individual_body);

    // Modify a Pattern incorrectly: 422.
    $temp_copy = $pattern_to_send['component_tree']['tree'];
    $pattern_to_send['component_tree']['tree'] = '{}';
    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/pattern/test'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The root UUID is missing.',
          'source' => ['pointer' => 'component_tree.tree[a548b48d-58a8-4077-aa04-da9405a6f418]'],
        ],
      ],
    ], $body);

    $pattern_to_send['component_tree']['tree'] = $temp_copy;

    // Modify a Pattern correctly: 200.
    $request_options = [
      RequestOptions::JSON => $pattern_to_send,
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/pattern/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_pattern_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "$base/xb/api/config/pattern/test" => $expected_pattern_normalization,
    ], $body);

    // Delete the sole Pattern via the XB HTTP API: 204.
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/pattern/test'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/config/pattern/test'), [], 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
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
