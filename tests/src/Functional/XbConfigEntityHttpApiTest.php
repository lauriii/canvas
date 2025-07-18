<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Audit\ComponentAudit;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \Drupal\experience_builder\Controller\ApiConfigControllers
 * @covers \Drupal\experience_builder\Controller\ApiConfigAutoSaveControllers
 * @group experience_builder
 * @internal
 */
class XbConfigEntityHttpApiTest extends HttpApiTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use OpenApiSpecTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'experience_builder',
    'xb_test_sdc',
    'node',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser([
      'administer themes',
      Page::EDIT_PERMISSION,
      Component::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
      Pattern::ADMIN_PERMISSION,
    ]);
    assert($user instanceof UserInterface);
    $this->httpApiUser = $user;
    $this->createContentType(['type' => 'article']);
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
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/xb/api/v0/config/menu'), []);
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type')[0]);

    // Even as a logged in user with correct permission.
    $this->drupalLogin($this->httpApiUser);
    $response = $this->makeApiRequest('GET', Url::fromUri('base:/xb/api/v0/config/menu'), []);
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
    $list_url = Url::fromUri("base:/xb/api/v0/config/pattern");
    $canonical_url = Url::fromUri("base:/xb/api/v0/config/pattern/disabled_pattern");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $pattern = Pattern::create([
      'id' => 'disabled_pattern',
      'label' => 'Disabled Pattern',
      'status' => FALSE,
      'component_tree' => [
          [
            'uuid' => '75144f9b-1bfc-4874-b848-b5889f066217',
            'component_id' => 'sdc.xb_test_sdc.druplicon',
            'component_version' => '8fe3be948e0194e1',
            'inputs' => [],
          ],
      ],
    ]);
    $pattern->save();
    // Get the pattern list the XB HTTP API should not return unpublished ones.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Admin should not be able to get disabled pattern from the XB HTTP API.
    $body = $this->assertExpectedResponse('GET', $canonical_url, [], 403, ['user.permissions'], ['4xx-response', 'config:experience_builder.pattern.disabled_pattern', 'http_response'], 'UNCACHEABLE (request policy)', NULL);
    $this->assertSame([
      'errors' => [''],
    ], $body);

    $pattern->delete();

    // Create a Pattern via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $pattern_to_send = [
      'name' => 'Test pattern, please ignore',
      'layout' => NULL,
      'model' => NULL,
    ];
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/pattern]. [Keyword validation failed: Value cannot be null in layout]',
    ], $body, 'Fails with missing data.');

    // Add missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $pattern_to_send['layout'] = [
      [
        'uuid' => 'a3ade070-dc70-4989-b078-85cfd8fc741e',
        'nodeType' => 'component',
        'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
        'slots' => [],
      ],
      [
        'uuid' => '67d6b081-a62f-463c-a5d8-42a145ec7243',
        'nodeType' => 'component',
        'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
        'slots' => [],
      ],
    ];
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/pattern]. [Keyword validation failed: Value cannot be null in model]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate internal consistency for
    // `model` (`inputs` on server side): 422 (i.e. validation constraint
    // violation).
    $pattern_to_send['model'] = [];
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The property heading is required.',
          'source' => ['pointer' => 'model.a3ade070-dc70-4989-b078-85cfd8fc741e.heading'],
        ],
        [
          'detail' => 'The property heading is required.',
          'source' => ['pointer' => 'model.67d6b081-a62f-463c-a5d8-42a145ec7243.heading'],
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
      'a3ade070-dc70-4989-b078-85cfd8fc741e' => [
        'resolved' => [
          'heading' => $generate_static_prop_source('world')['value'],
        ],
        'source' => [
          'heading' => $generate_static_prop_source('world'),
        ],
      ],
      '67d6b081-a62f-463c-a5d8-42a145ec7243' => [
        'resolved' => [
          'heading' => $generate_static_prop_source('another world')['value'],
        ],
        'source' => [
          'heading' => $generate_static_prop_source('another world'),
        ],
      ],
    ];
    $pattern_to_send['layout'][] = [
      'uuid' => 'f01b9dc4-9a25-4ff0-ac79-c336f4f5b1cb',
      'nodeType' => 'component',
      'type' => 'block.system_main_block@irrelevant',
      'slots' => [],
    ];
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => "The 'experience_builder.component.block.system_main_block' config does not exist.",
          'source' => ['pointer' => 'layout.children.2.component_id'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    // Create a Pattern via the XB HTTP API, correctly: 201.
    array_pop($pattern_to_send['layout']);
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/v0/config/pattern/testpatternpleaseignore",
      ],
    ]);
    $expected_pattern_normalization = [
      'layoutModel' => [
        'layout' => [
          [
            'uuid' => 'a3ade070-dc70-4989-b078-85cfd8fc741e',
            'nodeType' => 'component',
            'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
            'name' => NULL,
            'slots' => [],
          ],
          [
            'uuid' => '67d6b081-a62f-463c-a5d8-42a145ec7243',
            'nodeType' => 'component',
            'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
            'name' => NULL,
            'slots' => [],
          ],
        ],
        'model' => [
          'a3ade070-dc70-4989-b078-85cfd8fc741e' => [
            'source' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
            'resolved' => [
              'heading' => 'Hello, world!',
            ],
          ],
          '67d6b081-a62f-463c-a5d8-42a145ec7243' => [
            'source' => [
              'heading' => [
                'sourceType' => 'static:field_item:string',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
            'resolved' => [
              'heading' => 'Hello, another world!',
            ],
          ],
        ],
      ],
      'name' => 'Test pattern, please ignore',
      'id' => 'testpatternpleaseignore',
      'default_markup' => '<!-- xb-start-a3ade070-dc70-4989-b078-85cfd8fc741e --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-a3ade070-dc70-4989-b078-85cfd8fc741e/heading -->Hello, world!<!-- xb-prop-end-a3ade070-dc70-4989-b078-85cfd8fc741e/heading --></h1>
</div>
<!-- xb-end-a3ade070-dc70-4989-b078-85cfd8fc741e --><!-- xb-start-67d6b081-a62f-463c-a5d8-42a145ec7243 --><div  data-component-id="xb_test_sdc:props-no-slots" style="font-family: Helvetica, Arial, sans-serif; width: 100%; height: 100vh; background-color: #f5f5f5; display: flex; justify-content: center; align-items: center; flex-direction: column; text-align: center; padding: 20px; box-sizing: border-box;">
  <h1 style="font-size: 3em; margin: 0.5em 0; color: #333;"><!-- xb-prop-start-67d6b081-a62f-463c-a5d8-42a145ec7243/heading -->Hello, another world!<!-- xb-prop-end-67d6b081-a62f-463c-a5d8-42a145ec7243/heading --></h1>
</div>
<!-- xb-end-67d6b081-a62f-463c-a5d8-42a145ec7243 -->',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];
    $this->assertSame($expected_pattern_normalization, $body);

    // Creating a Pattern with an already-in-use ID: 409.
    $request_options[RequestOptions::JSON] = ['id' => 'testpatternpleaseignore'] + $pattern_to_send;
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
                'uuid' => 'a3ade070-dc70-4989-b078-85cfd8fc741e',
                'nodeType' => 'component',
                'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
                'slots' => [],
              ],
              [
                'uuid' => '67d6b081-a62f-463c-a5d8-42a145ec7243',
                'nodeType' => 'component',
                'type' => 'sdc.xb_test_sdc.props-no-slots@95f4f1d5ee47663b',
                'slots' => [],
              ],
            ],
            'id' => 'c4074d1f-149a-4662-aaf3-615151531cf6/content',
            'name' => 'content',
            'nodeType' => 'slot',
          ],
        ],
        'type' => 'sdc.xb_test_sdc.one_column@836c8835c850cdc5',
        'uuid' => 'c4074d1f-149a-4662-aaf3-615151531cf6',
      ],
    ];
    $request_options[RequestOptions::JSON] = $nested_components_pattern;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The property width is required.',
          'source' => ['pointer' => 'model.c4074d1f-149a-4662-aaf3-615151531cf6.width'],
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
              'allowed_values_function' => 'experience_builder_load_allowed_values_for_component_prop',
            ],
          ],
        ],
      ],
    ];
    $request_options[RequestOptions::JSON] = $nested_components_pattern;
    $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/v0/config/pattern/nested",
      ],
    ]);

    // Delete the nested Pattern via the XB HTTP API: 204.
    $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/v0/config/pattern/nested'), [], 204, NULL, NULL, NULL, NULL);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots', 'config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "testpatternpleaseignore" => $expected_pattern_normalization,
    ], $body);
    // Use the individual URL in the list response body.
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots', 'config:experience_builder.pattern.testpatternpleaseignore', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $expected_individual_body_normalization = $expected_pattern_normalization;
    $expected_individual_body_normalization['js_footer'] = str_replace('xb\/api\/config\/pattern', 'xb\/api\/config\/pattern\/testpatternpleaseignore', $expected_pattern_normalization['js_footer']);
    $this->assertSame($expected_individual_body_normalization, $individual_body);

    // Modify a Pattern incorrectly (shape-wise): 500.
    $request_options[RequestOptions::JSON] = [
      'name' => $pattern_to_send['name'],
      'layout' => $pattern_to_send['layout'],
      'model' => NULL,
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/v0/config/pattern/{configEntityId}]. [Keyword validation failed: Value cannot be null in model]',
    ], $body, 'Fails with an invalid pattern.');

    // Modify a Pattern incorrectly (consistency-wise): 422.
    $request_options[RequestOptions::JSON] = [
      'name' => $pattern_to_send['name'],
      'layout' => $pattern_to_send['layout'],
      'model' => array_slice($pattern_to_send['model'], 1),
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The property heading is required.',
          'source' => ['pointer' => 'model.a3ade070-dc70-4989-b078-85cfd8fc741e.heading'],
        ],
      ],
    ], $body);

    // Modify a Pattern correctly: 200.
    $request_options[RequestOptions::JSON] = $pattern_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_individual_body_normalization, $body);

    // Partially modify a Pattern: 200.
    $pattern_to_send['name'] = 'Updated test pattern name';
    $expected_individual_body_normalization['name'] = $pattern_to_send['name'];
    $expected_pattern_normalization['name'] = $pattern_to_send['name'];
    $request_options[RequestOptions::JSON] = [
      'name' => $pattern_to_send['name'],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_individual_body_normalization, $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'user.permissions', 'theme'], ['config:experience_builder.component.sdc.xb_test_sdc.props-no-slots', 'config:pattern_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      "testpatternpleaseignore" => $expected_pattern_normalization,
    ], $body);

    // Disable the pattern.
    Pattern::load('testpatternpleaseignore')?->disable()->save();
    // Assert that it no longer shows in the list.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, [
      'user.permissions',
    ], [
      'config:pattern_list',
      'http_response',
    ], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Delete the sole remaining Pattern via the XB HTTP API: 204.
    $this->assertDeletionAndEmptyList(Url::fromUri('base:/xb/api/v0/config/pattern/testpatternpleaseignore'), $list_url, 'config:pattern_list');

    // This was now tested full circle! ✅
  }

  /**
   * @see \Drupal\experience_builder\Entity\JavaScriptComponent
   */
  public function testJavaScriptComponent(): void {
    $this->assertAuthenticationAndAuthorization(JavaScriptComponent::ENTITY_TYPE_ID);

    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri('base:/xb/api/v0/config/js_component');
    $auto_save_url = Url::fromUri("base:/xb/api/v0/config/auto-save/js_component/test");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $jsComponent = JavaScriptComponent::create([
      'machineName' => 'disabled_js_component',
      'name' => 'Disabled JavaScript Component',
      'status' => FALSE,
      'props' => [],
      'slots' => [],
      'js' => [
        'original' => '',
        'compiled' => '',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'importedJsComponents' => [],
    ]);
    $jsComponent->save();
    // Get the Code Components list via the XB HTTP API should return
    // unpublished ones too.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['languages:language_interface', 'theme', 'user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      'disabled_js_component' => [
        'machineName' => 'disabled_js_component',
        'name' => 'Disabled JavaScript Component',
        'status' => FALSE,
        'props' => [],
        'required' => [],
        'slots' => [],
        'sourceCodeJs' => '',
        'sourceCodeCss' => '',
        'compiledJs' => '',
        'compiledCss' => '',
        'default_markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
        'css' => '',
        'js_header' => '',
        'js_footer' => '',
      ],
    ], $body);
    $canonical_url = Url::fromUri('base:/xb/api/v0/config/js_component/disabled_js_component');
    $body = $this->assertExpectedResponse('GET', $canonical_url, [], 200, ['languages:language_interface', 'theme', 'user.permissions'], ['config:experience_builder.js_component.disabled_js_component', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      'machineName' => 'disabled_js_component',
      'name' => 'Disabled JavaScript Component',
      'status' => FALSE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'default_markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ], $body);
    $jsComponent->delete();

    // Create a Code Component via the XB HTTP API, but forget crucial data: 500, courtesy of OpenAPI.
    $code_component_to_send = [];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/js_component]. [Keyword validation failed: Required property \'name\' must be present in the object in name]',
    ], $body, 'Fails with missing data.');

    // Add most missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $code_component_to_send = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => NULL,
      'sourceCodeCss' => NULL,
      'compiledJs' => NULL,
      'compiledCss' => NULL,
      'importedJsComponents' => [],
    ];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/js_component]. [Keyword validation failed: Required property \'status\' must be present in the object in status]',
    ], $body, 'Fails with invalid shape.');
    $code_component_to_send['status'] = FALSE;
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/js_component]. [Keyword validation failed: Value cannot be null in sourceCodeJs]',
    ], $body, 'Fails with invalid shape.');

    $code_component_to_send = [
      'machineName' => 'test',
      'status' => TRUE,
      'name' => 'Test Code Component',
      'props' => [],
      'slots' => [
        'test-slot' => [
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
        'test-slot-only-required' => [
          'title' => 'test',
        ],
      ],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
    ];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/js_component]. [Keyword validation failed: Required property \'title\' must be present in the object in slots->test-slot->title]',
    ], $body, 'Fails with invalid shape.');

    // Create a Code Component via the XB HTTP API, but forget 'importedJsComponents': 500, courtesy of OpenAPI.
    $code_component_to_send['slots']['test-slot']['title'] = 'Test';
    unset($code_component_to_send['importedJsComponents']);
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/js_component]. [Keyword validation failed: Required property \'importedJsComponents\' must be present in the object in importedJsComponents]',
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
          'type' => 'nonsense',
        ],
      ],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
    ];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Unable to find class/interface "nonsense" specified in the prop "incorrect" for the component "experience_builder:test".',
          'source' => ['pointer' => ''],
        ],
        [
          'detail' => 'The value you selected is not a valid choice.',
          'source' => ['pointer' => 'props.incorrect.type'],
        ],
      ],
    ], $body);

    // Meet data shape requirements, but provide missing component as a
    // dependency in `importedJsComponents`: 422
    // (i.e. validation constraint violation).
    $code_component_to_send = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => ['missing'],
    ];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => "The JavaScript component with the machine name 'missing' does not exist.",
          'source' => ['pointer' => 'importedJsComponents.0'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSame([], $body);

    $dependency_component = JavaScriptComponent::create([
      'machineName' => 'another_component',
      'name' => 'Test',
      'status' => FALSE,
      'props' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
    ]);
    $this->assertSame(SAVED_NEW, $dependency_component->save());
    $expected_dependency_component = $dependency_component->normalizeForClientSide()->values +
    [
      'default_markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];

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
          'title' => 'Title',
          'type' => 'string',
          'examples' => ['Press', 'Submit now'],
        ],
        'boolean' => [
          'title' => 'Truth',
          'type' => 'boolean',
          'examples' => [TRUE, FALSE],
        ],
        'integer' => [
          'title' => 'Integer',
          'type' => 'integer',
          'examples' => [23, 10, 2024],
        ],
        'number' => [
          'title' => 'Number',
          'type' => 'number',
          'examples' => [3.14, 42],
        ],
        // Enum with meta-enum, as this is not mandatory in SDC < 11.2, but it is in XB.
        'enum' => [
          'type' => 'string',
          'title' => 'Enum',
          'enum' => ['primary', 'secondary'],
          'examples' => ['primary'],
          'meta:enum' => [
            'primary' => 'Primary',
            'secondary' => 'Secondary',
          ],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
        'test-slot-only-required' => [
          'title' => 'test',
        ],
      ],
      'sourceCodeJs' => 'console.log("Test")',
      'sourceCodeCss' => '.test { display: none; }',
      'compiledJs' => 'console.log("Test")',
      'compiledCss' => '.test{display:none;}',
      'importedJsComponents' => ['another_component'],
    ];
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/v0/config/js_component/test",
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
          'examples' => [TRUE, FALSE],
        ],
        'integer' => [
          'title' => 'Integer',
          'type' => 'integer',
          'examples' => [23, 10, 2024],
        ],
        'number' => [
          'title' => 'Number',
          'type' => 'number',
          'examples' => [3.14, 42],
        ],
        'enum' => [
          'title' => 'Enum',
          'type' => 'string',
          'examples' => ['primary'],
          'enum' => ['primary', 'secondary'],
          'meta:enum' => ['primary' => 'Primary', 'secondary' => 'Secondary'],
        ],
      ],
      'required' => [
        'string',
        'integer',
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
        'test-slot-only-required' => [
          'title' => 'test',
        ],
      ],
      'sourceCodeJs' => 'console.log("Test")',
      'sourceCodeCss' => '.test { display: none; }',
      'compiledJs' => 'console.log("Test")',
      'compiledCss' => '.test{display:none;}',
      'default_markup' => '@todo Make something 🆒 in https://www.drupal.org/project/experience_builder/issues/3498889',
      'css' => '',
      'js_header' => '',
      'js_footer' => '',
    ];
    $this->assertSame($expected_component, $body);
    // Confirm that the code components ARE NOT exposed.
    // @see docs/config-management.md#3.2.1
    $this->assertExposedCodeComponents([], 'MISS', $request_options);
    $this->assertExposedCodeComponents([], 'HIT', $request_options);
    // Confirm no auto-save entity has been created.
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'config:experience_builder.js_component.another_component', 'config:experience_builder.js_component.test'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'config:experience_builder.js_component.another_component', 'config:experience_builder.js_component.test'], 'UNCACHEABLE (request policy)', 'HIT');

    // Creating a JavaScriptComponent with an already-in-use ID: 409.
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 409, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "'js_component' entity with ID 'test' already exists.",
      ],
    ], $body);

    // Admin should be able to get the Code Component from the XB HTTP API.
    $canonical_url = Url::fromUri('base:/xb/api/v0/config/js_component/test');
    $body = $this->assertExpectedResponse('GET', $canonical_url, [], 200, ['languages:language_interface', 'theme', 'user.permissions'], ['config:experience_builder.js_component.another_component', 'config:experience_builder.js_component.test', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame($expected_component, $body);

    // Editing the previous JavaScriptComponent to PATCH `meta:enum`,
    // which should be allowed.
    $request_options[RequestOptions::JSON] = NestedArray::mergeDeep($code_component_to_send, [
      'props' => [
        'enum' => [
          'meta:enum' => ['primary' => 'Primary Value', 'secondary' => 'Secondary Value'],
        ],
      ],
    ]);
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame(NestedArray::mergeDeep($expected_component, [
      'props' => [
        'enum' => [
          'meta:enum' => ['primary' => 'Primary Value', 'secondary' => 'Secondary Value'],
        ],
      ],
    ]), $body);

    // Modify a JavaScriptComponent incorrectly (shape-wise): 500.
    $request_options[RequestOptions::JSON] = [
      'machineName' => [$code_component_to_send['machineName']],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/v0/config/js_component/{configEntityId}]. [Value expected to be \'string\', but \'array\' given in machineName]',
    ], $body, 'Fails with an invalid code component.');

    // Modify a Code Component incorrectly (consistency-wise): 422.
    $omitted_string_prop_title = $code_component_to_send['props']['string']['title'];
    unset($code_component_to_send['props']['string']['title']);
    $omitted_required_prop_examples = $code_component_to_send['props']['integer']['examples'];
    unset($code_component_to_send['props']['integer']['examples']);
    unset($code_component_to_send['props']['number']['examples']);
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Prop "string" must have title',
          'source' => ['pointer' => ''],
        ],
        [
          'detail' => 'Prop "integer" is required, but does not have example value',
          'source' => ['pointer' => ''],
        ],
        [
          'detail' => "'title' is a required key.",
          'source' => ['pointer' => 'props.string'],
        ],
      ],
    ], $body);

    // Fix the errors above.
    $code_component_to_send['name'] = 'Test, and test again';
    $code_component_to_send['props']['string']['title'] = $omitted_string_prop_title;
    $code_component_to_send['props']['integer']['examples'] = $omitted_required_prop_examples;
    $expected_component['name'] = $code_component_to_send['name'];
    unset($expected_component['props']['number']['examples']);

    // Modify a Code Component incorrectly (consistency-wise): 422.
    // Missing `importedJsComponents` when `sourceCodeJs` is provided.
    unset($code_component_to_send['compiledJs']);
    unset($code_component_to_send['importedJsComponents']);
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 422, NULL, NULL, NULL, NULL);
    $missing_imported_js_components_error = [
      'errors' => [
        [
          'detail' => "The 'importedJsComponents' field is required when 'sourceCodeJs' or 'compiledJs' is provided",
          'source' => ['pointer' => 'importedJsComponents'],
        ],
      ],
    ];
    $this->assertSame($missing_imported_js_components_error, $body);

    // Modify a Code Component incorrectly (consistency-wise): 422.
    // Missing `sourceCodeJs` when `compiledJs` is provided.
    $code_component_to_send['compiledJs'] = 'console.log("Test")';
    unset($code_component_to_send['sourceCodeJs']);
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame($missing_imported_js_components_error, $body);

    // Modify a Code Component correctly: 200.
    $code_component_to_send['importedJsComponents'] = ['another_component'];
    $code_component_to_send['sourceCodeJs'] = 'console.log("Test")';
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);

    // Partially modify a Code Component: 200
    $code_component_to_send['name'] = 'Test once again for good luck';
    $expected_component['name'] = $code_component_to_send['name'];
    $request_options[RequestOptions::JSON] = [
      'name' => $code_component_to_send['name'],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);

    // Re-retrieve list: 200, non-empty list, despite `status` of entity being
    // `false`. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ], [
      'config:js_component_list',
      'http_response',
    ], 'UNCACHEABLE (request policy)', 'MISS');
    // Ensure the order matches.
    \assert(\is_array($body));
    \ksort($body);
    $this->assertSame(
      [
        'another_component' => $expected_dependency_component,
        'test' => $expected_component,
      ],
      $body
    );
    // Confirm that the code component IS STILL NOT exposed, because `status` is
    // still `FALSE`.
    // @see docs/config-management.md#3.2.1
    $this->assertExposedCodeComponents([], 'HIT', $request_options);

    // Create an auto-save entry for this config entity, to verify that neither
    // the "list" nor the "individual" API responses tested here are affected by
    // it.
    $auto_save_data = $code_component_to_send;
    $auto_save_data['name'] = 'Auto-save title, should not affect GET requests';
    $auto_save_data['props']['string'] = [
      'title' => 'Title (new)',
    ] + $auto_save_data['props']['string'];
    $expected_auto_save = $expected_component;
    $expected_auto_save['name'] = $auto_save_data['name'];
    $expected_auto_save['props']['string']['title'] = 'Title (new)';
    // Expected component has the keys in the same order as the schema, because
    // it is dealing with a saved component. For auto-saves the order of keys
    // sent by the client is what we reflect back because the entity has not
    // been saved or validated. Update the expected value so the order of keys
    // matches what we sent.
    $expected_auto_save['props']['enum'] = [
      'type' => 'string',
      'title' => 'Enum',
      'enum' => [
        'primary',
        'secondary',
      ],
      'examples' => [
        'primary',
      ],
      'meta:enum' => [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
      ],
    ];
    unset($expected_auto_save['css'], $expected_auto_save['default_markup'], $expected_auto_save['js_footer'], $expected_auto_save['js_header']);
    $this->performAutoSave($auto_save_data, $expected_auto_save, JavaScriptComponent::ENTITY_TYPE_ID, 'test');

    // Modify a Code Component correctly: 200.
    // ⚠️This is changing it from `internal` → `exposed`, for the first time,
    // this must trigger the creation a corresponding `Component` config entity.
    $this->assertNull(Component::load('js.test'));
    // @todo https://www.drupal.org/i/3500043 will disallow PATCHing this if > 0 uses of this component exist.
    $code_component_to_send['status'] = TRUE;
    $expected_component['status'] = TRUE;
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);
    // Confirm that the code component IS exposed, because `status` was just
    // changed to `TRUE`.
    // @see docs/config-management.md#3.2.1
    $this->assertNotNull(Component::load('js.test'));
    $this->assertTrue(Component::load('js.test')->status());
    $this->assertExposedCodeComponents(['js.test'], 'MISS', $request_options, [
      AutoSaveManager::CACHE_TAG,
      'config:experience_builder.js_component.another_component',
      'config:experience_builder.js_component.test',
    ]);
    $this->assertExposedCodeComponents(['js.test'], 'HIT', $request_options, [
      AutoSaveManager::CACHE_TAG,
      'config:experience_builder.js_component.another_component',
      'config:experience_builder.js_component.test',
    ]);
    // Confirm that there STILL is an auto-save, and its `status` was updated!
    $expected_auto_save['status'] = TRUE;
    $this->assertCurrentAutoSave(200, $expected_auto_save, JavaScriptComponent::ENTITY_TYPE_ID, 'test');

    // Modify a Code Component correctly: 200.
    // ⚠️This is changing it from `exposed` → `internal`. This must cause the
    // `Component` config entity to continue to exist, but get its `status` to
    // change to `FALSE`, and cause it to be omitted from the list of available
    // components for the Content Creator.
    // @todo https://www.drupal.org/i/3500043 will disallow PATCHing this if > 0 uses of this component exist.
    $code_component_to_send['status'] = FALSE;
    $expected_component['status'] = FALSE;
    $request_options[RequestOptions::JSON] = $code_component_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/js_component/test'), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($expected_component, $body);
    // Confirm that the code component still IS exposed (a Component config
    // entity still exists), but is disabled aka not available to be placed (the
    // Component config entity's `status` was just changed to `FALSE`).
    // @see docs/config-management.md#3.2.1
    $component = Component::load('js.test');
    $this->assertFalse($component->status());
    $this->assertExposedCodeComponents([], 'MISS', $request_options, []);
    $this->assertExposedCodeComponents([], 'HIT', $request_options, []);

    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ], [
      'config:js_component_list',
      'http_response',
    ], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([
      'another_component' => $expected_dependency_component,
      'test' => $expected_component,
    ], $body);

    // Create a new auto-save entry.
    $auto_save_data = $code_component_to_send;
    $auto_save_data['name'] = 'Auto-save title, should not affect GET requests';
    $auto_save_data['props']['string'] = [
      'title' => 'Title (new)',
    ] + $auto_save_data['props']['string'];
    $expected_auto_save = $expected_component;
    $expected_auto_save['name'] = $auto_save_data['name'];
    $expected_auto_save['props']['string']['title'] = 'Title (new)';
    // Expected component has the keys in the same order as the schema, because
    // it is dealing with a saved component. For auto-saves the order of keys
    // sent by the client is what we reflect back because the entity has not
    // been saved or validated. Update the expected value so the order of keys
    // matches what we sent.
    $expected_auto_save['props']['enum'] = [
      'type' => 'string',
      'title' => 'Enum',
      'enum' => [
        'primary',
        'secondary',
      ],
      'examples' => [
        'primary',
      ],
      'meta:enum' => [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
      ],
    ];
    unset($expected_auto_save['css'], $expected_auto_save['default_markup'], $expected_auto_save['js_footer'], $expected_auto_save['js_header']);
    $this->performAutoSave($auto_save_data, $expected_auto_save, JavaScriptComponent::ENTITY_TYPE_ID, 'test');

    // We can delete the 'test' Code Component via the XB HTTP API. As it isn't
    // in use it will cascade delete the component as well.
    $component_storage = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID);
    $component = $component_storage->loadUnchanged('js.test');
    \assert($component instanceof ComponentInterface);
    self::assertFalse(\Drupal::service(ComponentAudit::class)->hasUsages($component));
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/v0/config/js_component/test'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);
    $component = $component_storage->loadUnchanged('js.test');
    self::assertNull($component);

    // Delete the 'another_component' Code Component via the XB HTTP API: 204.
    $body = $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/v0/config/js_component/another_component'), [], 204, NULL, NULL, NULL, NULL);
    $this->assertNull($body);

    // Confirm that the code component IS NOT exposed, because it no longer
    // exists.
    // @see docs/config-management.md#3.2.1
    $this->assertExposedCodeComponents([], 'MISS', $request_options);
    $this->assertExposedCodeComponents([], 'HIT', $request_options);
    // Confirm that there is no auto-save anymore.
    $this->assertCurrentAutoSave(404, NULL, JavaScriptComponent::ENTITY_TYPE_ID, 'test');

    // Re-retrieve list: 200, empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:js_component_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/v0/config/js_component/test'), [], 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame([], $individual_body);
  }

  public function testAssetLibrary(): void {
    // Delete the library created during install.
    $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    \assert($library instanceof AssetLibrary);
    $library->delete();
    $this->assertAuthenticationAndAuthorization('xb_asset_library', FALSE);

    $base = rtrim(base_path(), '/');
    $list_url = Url::fromUri("base:/xb/api/v0/config/xb_asset_library");
    $canonical_url = Url::fromUri("base:/xb/api/v0/config/xb_asset_library/global");
    $auto_save_url = Url::fromUri("base:/xb/api/v0/config/auto-save/xb_asset_library/global");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Manually delete.
    $library->delete();
    $library = AssetLibrary::create([
      'id' => AssetLibrary::GLOBAL_ID,
      'label' => 'Disabled Global Library Component',
      'status' => FALSE,
      'js' => [
        'original' => '',
        'compiled' => '',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
    ]);
    $library->save();
    // Get the Asset Libraries list via the XB HTTP API should not return
    // unpublished ones.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:xb_asset_library_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame([], $body);

    // Admin should not be able to get disabled Asset Library from the XB HTTP API.
    $body = $this->assertExpectedResponse('GET', $canonical_url, [], 403, ['user.permissions'], ['4xx-response', 'config:experience_builder.xb_asset_library.global', 'http_response'], 'UNCACHEABLE (request policy)', NULL);
    $this->assertSame([
      'errors' => [''],
    ], $body);
    $library->delete();

    // Create an Asset Library via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $asset_library_to_send = [
      'id' => 'global',
      'label' => NULL,
      'css' => NULL,
      'js' => NULL,
    ];
    $request_options[RequestOptions::JSON] = $asset_library_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/xb_asset_library]. [Keyword validation failed: Value cannot be null in label]',
    ], $body, 'Fails with missing data.');

    // Add missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $asset_library_to_send['label'] = 'Test Asset Library';
    $asset_library_to_send['css'] = [
      'original' => 'body { background-color: #000; }',
      'compiled' => NULL,
    ];
    $request_options[RequestOptions::JSON] = $asset_library_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/xb_asset_library]. [Keyword validation failed: Value cannot be null in css->compiled]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate internal consistency for
    // `id`: 422 (i.e. validation constraint violation).
    $asset_library_to_send['css']['compiled'] = 'body{background-color:#000}';
    $asset_library_to_send['id'] = 'not_global';
    $request_options[RequestOptions::JSON] = $asset_library_to_send;
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
    $request_options[RequestOptions::JSON] = $asset_library_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/v0/config/xb_asset_library/global",
      ],
    ]);
    $this->assertSame($body, $asset_library_to_send);
    // Confirm no auto-save entity has been created.
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'config:experience_builder.xb_asset_library.global'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 200, ['user.permissions'], [AutoSaveManager::CACHE_TAG, 'http_response', 'config:experience_builder.xb_asset_library.global'], 'UNCACHEABLE (request policy)', 'HIT');

    // Modify the asset library: 200.
    $asset_library_to_send['label'] = 'Updated asset library label';
    $request_options[RequestOptions::JSON] = [
      'label' => $asset_library_to_send['label'],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri("base:/xb/api/v0/config/xb_asset_library/global"), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertSame($body, $asset_library_to_send);

    // @todo Test that creating an auto-save entry for the 'global' does not
    //   affect the GET request in https:/drupal.org/i/3505224.

    // Creating an Asset Library with an already-in-use ID: 409.
    $request_options[RequestOptions::JSON] = $asset_library_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 409, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "'xb_asset_library' entity with ID 'global' already exists.",
      ],
    ], $body);

    // Admin should be able to get the Asset Library from the XB HTTP API.
    $body = $this->assertExpectedResponse('GET', $canonical_url, [], 200, ['user.permissions'], ['config:experience_builder.xb_asset_library.global', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSame($asset_library_to_send, $body);

    // Cannot delete the global library.
    $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/v0/config/xb_asset_library/global'), [], 403, NULL, NULL, NULL, NULL);
  }

  private function assertAuthenticationAndAuthorization(string $entity_type_id, bool $delete_allowed = TRUE): void {
    $list_url = Url::fromUri("base:/xb/api/v0/config/$entity_type_id");

    // Anonymously: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'errors' => [
        'Requires >=1 content entity type with an XB field that can be created or edited.',
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

    // Create a new entity, so we can check GETing it after anonymously.
    $token = $this->drupalGet('session/token');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
    ];
    $request_options[RequestOptions::JSON] = $this->getConfigRequestPostExample($entity_type_id);
    $response = $this->makeApiRequest('POST', $list_url, $request_options);
    self::assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), TRUE);
    $idKey = $this->container->get(EntityTypeManagerInterface::class)->getDefinition($entity_type_id)->getKey('id');
    $this->assertArrayHasKey($idKey, $body);
    $id = $body[$idKey];

    $this->drupalLogout();
    // Verify we cannot access it as anonymous.
    $canonical_url = Url::fromUri("base:/xb/api/v0/config/$entity_type_id/$id");
    $response = $this->makeApiRequest('GET', $canonical_url, []);
    $this->assertSame(403, $response->getStatusCode());

    // Delete it to not affect further tests.
    $this->drupalLogin($this->httpApiUser);
    $token = $this->drupalGet('session/token');
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
    ];
    $response = $this->makeApiRequest('DELETE', $canonical_url, $request_options);
    $this->assertSame($delete_allowed ? 204 : 403, $response->getStatusCode());
  }

  private function getConfigRequestPostExample(string $entity_type_id): array {
    $openApiSpec = $this->getSpecification();
    $openApiSpecData = $openApiSpec->getSerializableData();
    $examples = (array) $openApiSpecData->paths->{'/xb/api/v0/config/' . $entity_type_id}->post->requestBody->content->{'application/json'}->examples;
    if (empty($examples)) {
      throw new \Exception('We require to define at least one example for POST "/xb/api/v0/config/' . $entity_type_id . '" request in openapi.yml.');
    }
    return (array) reset($examples);
  }

  private function assertExposedCodeComponents(array $expected, string $expected_dynamic_page_cache, array $request_options, array $additional_expected_cache_tags = []): void {
    assert(in_array($expected_dynamic_page_cache, ['HIT', 'MISS'], TRUE));
    $expected_contexts = [
      'languages:language_content',
      'languages:language_interface',
      'route',
      'theme',
      'url.path',
      'url.query_args',
      'user.node_grants:view',
      'user.permissions',
      'user.roles:authenticated',
      // The user_login_block is rendered as the anonymous user because for the
      // authenticated user it is empty.
      // @see \Drupal\experience_builder\Controller\ApiComponentsController::getCacheableClientSideInfo()
      'user.roles:anonymous',
    ];
    $expected_cache_tags = [
      'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form',
      'config:component_list',
      'config:core.extension',
      'config:node_type_list',
      'config:system.menu.account',
      'config:system.menu.admin',
      'config:system.menu.footer',
      'config:system.menu.main',
      'config:system.menu.tools',
      'config:system.site',
      'config:system.theme',
      'config:views.view.content_recent',
      'config:views.view.who_s_new',
      'http_response',
      'local_task',
      'node_list',
      'user:1',
      'user:2',
      'user_list',
    ];
    // If expected adds new components, those components add additional cache tags. If those cache tags are not
    // present, the test will fail. This array is used to add those additional expected cache tags.
    $expected_cache_tags = \array_values(Cache::mergeTags($expected_cache_tags, \array_values($additional_expected_cache_tags)));
    $body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/v0/config/component'), $request_options, 200, $expected_contexts, $expected_cache_tags, 'UNCACHEABLE (request policy)', $expected_dynamic_page_cache);
    self:self::assertNotNull($body);
    $component_config_entity_ids = array_keys($body);
    self::assertSame(
      $expected,
      array_values(array_filter($component_config_entity_ids, fn (string $id) => str_starts_with($id, 'js.'))),
    );
  }

}
