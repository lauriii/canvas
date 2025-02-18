<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;

/**
 * @covers \Drupal\experience_builder\Controller\ApiConfigControllers
 * @covers \Drupal\experience_builder\Controller\ApiConfigAutoSaveControllers
 * @group experience_builder
 * @internal
 */
class XbConfigEntityHttpApiTest extends HttpApiTestBase {

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
    $this->assertAuthenticationAndAuthorization('pattern');

    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri("base:/xb/api/config/pattern");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    // Create a Pattern via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $pattern_to_send = [
      'name' => 'Test pattern, please ignore',
      'layout' => NULL,
      'model' => NULL,
    ];
    // TRICKY: this intentionally avoids using RequestOptions::JSON because
    // that encodes `'inputs' => []` as `'inputs': []`, whereas the server side
    // expects `'inputs': {}`.
    // @see \Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait::encodeXBData()
    $request_options[RequestOptions::BODY] = self::encodeXBData($pattern_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/pattern]. [Keyword validation failed: Value cannot be null in layout]',
    ], $body, 'Fails with missing data.');

    // Add missing crucial data, but leave a required shape violation: 500,
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
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/pattern]. [Keyword validation failed: Value cannot be null in model]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate internal consistency for
    // `model` (`inputs` on server side): 422 (i.e. validation constraint
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
        'resolved' => [
          'heading' => $generate_static_prop_source('world')['value'],
        ],
        'source' => [
          'heading' => $generate_static_prop_source('world'),
        ],
      ],
      'uuid-in-root-another' => [
        'resolved' => [
          'heading' => $generate_static_prop_source('another world')['value'],
        ],
        'source' => [
          'heading' => $generate_static_prop_source('another world'),
        ],
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
          'detail' => 'The component <em class="placeholder">block.system_main_block</em> does not exist.',
          'source' => ['pointer' => 'layout.children[2]'],
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
            'resolved' => [
              'heading' => 'Hello, world!',
            ],
            'source' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
          ],
          'uuid-in-root-another' => [
            'resolved' => [
              'heading' => 'Hello, another world!',
            ],
            'source' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
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

    // Creating a Pattern with an already-in-use ID: 409.
    $request_options[RequestOptions::BODY] = self::encodeXBData(
      ['id' => 'testpatternpleaseignore'] + $pattern_to_send
    );
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 409, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "'pattern' entity with ID 'testpatternpleaseignore' already exists.",
      ],
    ], $body);

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
      'resolved' => [
        'width' => 'full',
      ],
      'source' => [
        'width' => [
          'sourceType' => 'static:field_item:list_string',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                [
                  'value' => 'full',
                  'label' => 'full',
                ],
                [
                  'value' => 'wide',
                  'label' => 'wide',
                ],
                [
                  'value' => 'normal',
                  'label' => 'normal',
                ],
                [
                  'value' => 'narrow',
                  'label' => 'narrow',
                ],
              ],
            ],
          ],
        ],
      ],
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
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/config/pattern/{configEntityId}]. [Keyword validation failed: Value cannot be null in model]',
    ], $body, 'Fails with an invalid pattern.');

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
   * @see \Drupal\experience_builder\Entity\JavaScriptComponent
   */
  public function testJavaScriptComponent(): void {
    $this->assertAuthenticationAndAuthorization('js_component');

    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri('base:/xb/api/config/js_component');
    $auto_save_url = Url::fromUri("base:/xb/api/config/auto-save/js_component/test");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Create a Code Component via the XB HTTP API, but forget crucial data: 500, courtesy of OpenAPI.
    $code_component_to_send = [];
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/js_component]. [Keyword validation failed: Required property \'name\' must be present in the object in name]',
    ], $body, 'Fails with missing data.');

    // Add most missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $code_component_to_send = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'props' => [],
      'slots' => [],
      'source_code_js' => NULL,
      'source_code_css' => NULL,
      'compiled_js' => NULL,
      'compiled_css' => NULL,
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/js_component]. [Keyword validation failed: Required property \'status\' must be present in the object in status]',
    ], $body, 'Fails with invalid shape.');
    $code_component_to_send['status'] = FALSE;
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/js_component]. [Keyword validation failed: Value cannot be null in source_code_js]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate internal consistency for
    // `props`: 422 (i.e. validation constraint violation).
    $code_component_to_send = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [
        'incorrect' => [
          'type' => 'test',
        ],
      ],
      'slots' => [],
      'source_code_js' => '',
      'source_code_css' => '',
      'compiled_js' => '',
      'compiled_css' => '',
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Unable to find class/interface "test" specified in the prop "incorrect" for the component "experience_builder:test".',
          'source' => ['pointer' => ''],
        ],
        [
          'detail' => "'title' is a required key.",
          'source' => ['pointer' => 'props.incorrect'],
        ],
        [
          'detail' => '\'examples\' is a required key.',
          'source' => ['pointer' => 'props.incorrect'],
        ],
        [
          'detail' => 'The value you selected is not a valid choice.',
          'source' => ['pointer' => 'props.incorrect.type'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    // Create a Code Component via the XB HTTP API, correctly: 201.
    $code_component_to_send = [
      'machineName' => 'test',
      'name' => 'Test',
      'status' => FALSE,
      'required' => [
        'string',
        'integer',
      ],
      'props' => [
        'string' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
        'boolean' => [
          'type' => 'boolean',
          'title' => 'Truth',
          'examples' => [TRUE, FALSE],
        ],
        'integer' => [
          'type' => 'integer',
          'title' => 'Integer',
          'examples' => [23, 10, 2024],
        ],
        'number' => [
          'type' => 'number',
          'title' => 'Number',
          'examples' => [3.14],
        ],
      ],
      'slots' => [],
      'source_code_js' => 'console.log("Test")',
      'source_code_css' => '.test { display: none; }',
      'compiled_js' => 'console.log("Test")',
      'compiled_css' => '.test{display:none;}',
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/js_component/test",
      ],
    ]);
    $expected_component = [
      'machineName' => 'test',
      'name' => 'Test',
      'status' => FALSE,
      'props' => [
        'string' => [
          'title' => 'Title',
          'type' => 'string',
          'examples' => ['Press', 'Submit now'],
        ],
        'boolean' => [
          'title' => 'Truth',
          'type' => 'boolean',
          'examples' => ['1', ''],
        ],
        'integer' => [
          'title' => 'Integer',
          'type' => 'integer',
          'examples' => ['23', '10', '2024'],
        ],
        'number' => [
          'title' => 'Number',
          'type' => 'number',
          'examples' => ['3.14'],
        ],
      ],
      'required' => [
        'string',
        'integer',
      ],
      'slots' => [],
      'source_code_js' => 'console.log("Test")',
      'source_code_css' => '.test { display: none; }',
      'compiled_js' => 'console.log("Test")',
      'compiled_css' => '.test{display:none;}',
      'default_markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];
    $this->assertSame($expected_component, $body);
    // Confirm no auto-save entity has been created.
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 204, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 204, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');

    // Creating a JavaScriptComponent with an already-in-use ID: 409.
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 409, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "'js_component' entity with ID 'test' already exists.",
      ],
    ], $body);

    // Modify a JavaScriptComponent incorrectly (shape-wise): 500.
    $request_options[RequestOptions::BODY] = self::encodeXBData([
      'machineName' => $code_component_to_send['machineName'],
    ]);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/js_component/test'), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/config/js_component/{configEntityId}]. [Keyword validation failed: Required property \'name\' must be present in the object in name]',
    ], $body, 'Fails with an invalid code component.');

    // Modify a Code Component incorrectly (consistency-wise): 422.
    $request_options[RequestOptions::BODY] = self::encodeXBData([
      'name' => 'Updated name',
      'machineName' => $code_component_to_send['machineName'],
      'required' => [
        'string',
        'integer',
      ],
      'status' => TRUE,
      'props' => [
        'integer' => [
          'title' => 'Integer',
          'type' => 'fake',
          'examples' => ['23', '10', '2024'],
        ],
      ],
      'slots' => [],
      'source_code_js' => 'console.log("Test")',
      'source_code_css' => '.test { display: none; }',
      'compiled_js' => 'console.log("Test")',
      'compiled_css' => '.test{display:none;}',
    ]);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/js_component/test'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Unable to find class/interface "fake" specified in the prop "integer" for the component "experience_builder:test".',
          'source' => ['pointer' => ''],
        ],
        [
          'detail' => 'The value you selected is not a valid choice.',
          'source' => ['pointer' => 'props.integer.type'],
        ],
      ],
    ], $body);

    // Modify a Code Component correctly: 200.
    $code_component_to_send['name'] = 'Test, and test again';
    $expected_component['name'] = $code_component_to_send['name'];
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);

    // Re-retrieve list: 200, non-empty list, despite `status` of entity being
    // `false`. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'theme', 'user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame(['test' => $expected_component], $body);

    // Modify a Code Component incorrectly (consistency-wise): 422.
    // @todo This currently returns a 200 response! https://www.drupal.org/i/3500043 will disallow PATCHing this if > 0 uses of this component exist.
    $code_component_to_send['status'] = TRUE;
    $expected_component['status'] = TRUE;
    $request_options[RequestOptions::BODY] = self::encodeXBData($code_component_to_send);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);

    // Create an auto-save entry for this config entity, to verify that neither
    // the "list" nor the "individual" API responses tested here are affected by
    // it.
    $auto_save_data = $code_component_to_send;
    $auto_save_data['name'] = 'Auto-save title, should not affect GET requests';
    $this->assertAutoSave($auto_save_data, 'js_component', 'test');

    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      'test' => $expected_component,
    ], $body);

    // Delete the 'test' Code Component via the XB HTTP API: 204.
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/js_component/test'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);

    // Re-retrieve list: 200, empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/config/js_component/test'), [], 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame([], $individual_body);
  }

  public function testAssetLibrary(): void {
    $this->assertAuthenticationAndAuthorization('xb_asset_library');

    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri("base:/xb/api/config/xb_asset_library");
    $auto_save_url = Url::fromUri("base:/xb/api/config/auto-save/xb_asset_library/global");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Create an Asset Library via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $asset_library_to_send = [
      'id' => 'global',
      'label' => NULL,
      'css' => NULL,
      'js' => NULL,
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($asset_library_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/xb_asset_library]. [Keyword validation failed: Value cannot be null in label]',
    ], $body, 'Fails with missing data.');

    // Add missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $asset_library_to_send['label'] = 'Test Asset Library';
    $asset_library_to_send['css'] = [
      'original' => 'body { background-color: #000; }',
      'compiled' => NULL,
    ];
    $request_options[RequestOptions::BODY] = self::encodeXBData($asset_library_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/config/xb_asset_library]. [Keyword validation failed: Value cannot be null in css->compiled]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate internal consistency for
    // `id`: 422 (i.e. validation constraint violation).
    $asset_library_to_send['css']['compiled'] = 'body{background-color:#000}';
    $asset_library_to_send['id'] = 'not_global';
    $request_options[RequestOptions::BODY] = self::encodeXBData($asset_library_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The <em class="placeholder">&quot;not_global&quot;</em> machine name is not valid.',
          'source' => ['pointer' => 'id'],
        ],
      ],
    ], $body);

    // Meet data shape requirements correctly: 201.
    $asset_library_to_send['id'] = 'global';
    $request_options[RequestOptions::BODY] = self::encodeXBData($asset_library_to_send);
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/xb_asset_library/global",
      ],
    ]);
    $this->assertSame($body, $asset_library_to_send);
    // Confirm no auto-save entity has been created.
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 204, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 204, ['user.permissions'], ['experience_builder__autosave', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');

    // @todo Test that creating an auto-save entry for the 'global' does not
    //   affect the GET request in https:/drupal.org/i/3505224.

    // Creating an Asset Library with an already-in-use ID: 409.
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 409, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "'xb_asset_library' entity with ID 'global' already exists.",
      ],
    ], $body);

    // Delete the Asset Library via the XB HTTP API: 204.
    $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/config/xb_asset_library/global'), [], 204, NULL, NULL, NULL, NULL);

    // Re-retrieve list: 200, empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:xb_asset_library_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
  }

  private function assertAuthenticationAndAuthorization(string $entity_type_id): void {
    $list_url = Url::fromUri("base:/xb/api/config/$entity_type_id");

    // Anonymously: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'errors' => [
        "The 'access administration pages' permission is required.",
      ],
    ], $body);

    // Authenticated & authorized: 200, but empty list.
    $this->drupalLogin($this->httpApiUser);
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ["config:{$entity_type_id}_list", 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
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
      'errors' => [
        "X-CSRF-Token request header is missing",
      ],
    ], json_decode((string) $response->getBody(), TRUE));
  }

}
