<?php

declare(strict_types=1);

use Drupal\Core\Url;
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
   * @see \Drupal\experience_builder\Entity\Pattern
   */
  public function testPattern(): void {
    // cspell:ignore testpatternpleaseignore
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

    // Send a POST request without the CSRF token.
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    $response = $this->makeApiRequest('POST', $list_url, $request_options);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame([
      'message' => "X-CSRF-Token request header is missing",
    ], json_decode((string) $response->getBody(), TRUE));

    // Create a Pattern via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $pattern_to_send = [
      'name' => 'Test pattern, please ignore',
      'layout' => NULL,
      'model' => NULL,
    ];
    // TRICKY: this intentionally avoids using RequestOptions::JSON because
    // that encodes `'props' => []` as `'props': []`, whereas the server side
    // expects `'props': {}`.
    // @see \Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait::encodeXBData()
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/pattern]',
    ], $body);

    // Add missing crucial data, but leave a requires shape violation: 500,
    // courtesy of OpenAPI.
    $pattern_to_send['layout'] = [
      [
        'uuid' => 'uuid-in-root',
        'nodeType' => 'component',
        'type' => 'sdc.xb_test_sdc.props-no-slots',
        'slots' => [],
      ],
      [
        'uuid' => 'uuid-in-root-another',
        'nodeType' => 'component',
        'type' => 'sdc.xb_test_sdc.props-no-slots',
        'slots' => [],
      ],
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/pattern]',
    ], $body);

    // Meet data shape requirements, but violate internal consistency for
    // `model` (`props` on server side): 422 (i.e. validation constraint
    // violation).
    $pattern_to_send['model'] = [];
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The required properties are missing.',
          'source' => ['pointer' => 'model.uuid-in-root'],
        ],
        [
          'detail' => 'The required properties are missing.',
          'source' => ['pointer' => 'model.uuid-in-root-another'],
        ],
      ],
    ], $body);

    // Meet data shape requirements, but violate internal consistency for
    // `layout` (`tree` on server side): 422 (i.e. validation constraint
    // violation).
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $pattern_to_send['model'] = [
      'uuid-in-root' => [
        'heading' => $generate_static_prop_source('world'),
      ],
      'uuid-in-root-another' => [
        'heading' => $generate_static_prop_source('another world'),
      ],
    ];
    $pattern_to_send['layout'][] = [
      'uuid' => 'uuid-main',
      'nodeType' => 'component',
      'type' => 'block.system_main_block',
      'slots' => [],
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The \'Drupal\Core\Block\MainContentBlockPluginInterface\' component interface must be absent.',
          'source' => ['pointer' => 'layout'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    // Create a Pattern via the XB HTTP API, correctly: 201.
    array_pop($pattern_to_send['layout']);
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/pattern/testpatternpleaseignore",
      ],
    ]);
    $expected_pattern_normalization = [
      'layoutModel' => [
        'layout' => [
          [
            'uuid' => 'uuid-in-root',
            'nodeType' => 'component',
            'type' => 'sdc.xb_test_sdc.props-no-slots',
            'slots' => [],
          ],
          [
            'uuid' => 'uuid-in-root-another',
            'nodeType' => 'component',
            'type' => 'sdc.xb_test_sdc.props-no-slots',
            'slots' => [],
          ],
        ],
        'model' => [
          'uuid-in-root' => [
            'heading' => 'Hello, world!',
          ],
          'uuid-in-root-another' => [
            'heading' => 'Hello, another world!',
          ],
        ],
      ],
      'name' => 'Test pattern, please ignore',
      'id' => 'testpatternpleaseignore',
      'default_markup' => '<!-- xb-start-uuid-in-root --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root/heading -->Hello, world!<!-- xb-prop-end-uuid-in-root/heading --></h1>
</div>
<!-- xb-end-uuid-in-root --><!-- xb-start-uuid-in-root-another --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-uuid-in-root-another/heading -->Hello, another world!<!-- xb-prop-end-uuid-in-root-another/heading --></h1>
</div>
<!-- xb-end-uuid-in-root-another -->',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];
    $this->assertSame($expected_pattern_normalization, $body);

    // Create a (more realistic) Pattern with nested components, but missing
    // prop: 422.
    $nested_components_pattern = $pattern_to_send;
    $nested_components_pattern['name'] = 'Nested';
    $nested_components_pattern['layout'] = [
      [
        'nodeType' => 'component',
        'slots' => [
          [
            'components' => [
              [
                'uuid' => 'uuid-in-root',
                'nodeType' => 'component',
                'type' => 'sdc.xb_test_sdc.props-no-slots',
                'slots' => [],
              ],
              [
                'uuid' => 'uuid-in-root-another',
                'nodeType' => 'component',
                'type' => 'sdc.xb_test_sdc.props-no-slots',
                'slots' => [],
              ],
            ],
            'id' => 'c4074d1f-149a-4662-aaf3-615151531cf6/content',
            'name' => 'content',
            'nodeType' => 'slot',
          ],
        ],
        'type' => 'sdc.experience_builder.one_column',
        'uuid' => 'c4074d1f-149a-4662-aaf3-615151531cf6',
      ],
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($nested_components_pattern);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The required properties are missing.',
          'source' => ['pointer' => 'model.c4074d1f-149a-4662-aaf3-615151531cf6'],
        ],
      ],
    ], $body);

    // Add missing missing prop: 201.
    $nested_components_pattern['model']['c4074d1f-149a-4662-aaf3-615151531cf6'] = [
      'width' => 'full',
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($nested_components_pattern);
    $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/pattern/nested",
      ],
    ]);

    // Delete the nested Pattern via the XB HTTP API: 204.
    $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/pattern/nested'), [], 204, NULL, NULL, NULL, NULL);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:pattern_list', 'http_response', 'config:experience_builder.component.sdc.xb_test_sdc.props-no-slots'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "testpatternpleaseignore" => $expected_pattern_normalization,
    ], $body);
    // Use the individual URL in the list response body.
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots', 'config:experience_builder.pattern.testpatternpleaseignore', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $expected_individual_body_normalization = $expected_pattern_normalization;
    $expected_individual_body_normalization['js_footer'] = str_replace('xb\/api\/config\/pattern', 'xb\/api\/config\/pattern\/testpatternpleaseignore', $expected_pattern_normalization['js_footer']);
    $this->assertSame($expected_individual_body_normalization, $individual_body);

    // Modify a Pattern incorrectly (shape-wise): 500.
    $request_options[RequestOptions::BODY] = self::encodeXBData([
      'name' => $pattern_to_send['name'],
      'layout' => $pattern_to_send['layout'],
      'model' => NULL,
    ]);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/config/pattern/{configEntityId}]',
    ], $body);

    // Modify a Pattern incorrectly (consistency-wise): 422.
    $request_options[RequestOptions::BODY] = self::encodeXBData([
      'name' => $pattern_to_send['name'],
      'layout' => $pattern_to_send['layout'],
      'model' => array_slice($pattern_to_send['model'], 1),
    ]);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The required properties are missing.',
          'source' => ['pointer' => 'model.uuid-in-root'],
        ],
      ],
    ], $body);

    // Modify a Pattern correctly: 200.
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_individual_body_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots', 'config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "testpatternpleaseignore" => $expected_pattern_normalization,
    ], $body);

    // Delete the sole remaining Pattern via the XB HTTP API: 204.
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);

    // Re-retrieve list: 200, empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/config/pattern/testpatternpleaseignore'), [], 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
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
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
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
