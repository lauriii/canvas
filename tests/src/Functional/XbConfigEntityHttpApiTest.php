<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Random;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityType;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Audit\ComponentAudit;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\Folder;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\node\Entity\Node;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\OpenApiSpecTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
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
  use GenerateComponentConfigTrait;
  use OpenApiSpecTrait;
  use AssertPageCacheContextsAndTagsTrait;
  use CreateTestJsComponentTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'experience_builder',
    'xb_test_sdc',
    // Validate that a single invalid SDC doesn't break the component list.
    'xb_broken_sdcs',
    'node',
    'field',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected array $defaultFolders = [
    '0bac3d4b-3ee1-47e7-9124-0d0b6882055d' => [
      'name' => 'Atom/Media',
      'id' => '0bac3d4b-3ee1-47e7-9124-0d0b6882055d',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.video',
      ],
    ],
    '1e06667f-933d-49ff-8966-c8ab5a11720d' => [
      'name' => 'Lists (Views)',
      'id' => '1e06667f-933d-49ff-8966-c8ab5a11720d',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.views_block.content_recent-block_1',
        'block.views_block.who_s_online-who_s_online_block',
      ],
    ],
    '344a9eb6-7abe-457e-b732-2698672f0779' => [
      'name' => 'Forms',
      'id' => '344a9eb6-7abe-457e-b732-2698672f0779',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.user_login_block',
      ],
    ],
    '359f17e0-9786-43e0-86cd-0619394bf12a' => [
      'name' => 'User',
      'id' => '359f17e0-9786-43e0-86cd-0619394bf12a',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.views_block.who_s_new-block_1',
      ],
    ],
    '54d29693-2b4e-46d2-83a6-8d6ffdbd7eae' => [
      'name' => 'Container/Special',
      'id' => '54d29693-2b4e-46d2-83a6-8d6ffdbd7eae',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.shoe_tab_group',
      ],
    ],
    '5ef4ff01-32b2-40d9-8471-15c9288c67ea' => [
      'name' => 'System',
      'id' => '5ef4ff01-32b2-40d9-8471-15c9288c67ea',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.system_clear_cache_block',
        'block.system_branding_block',
        'block.system_breadcrumb_block',
        'block.system_messages_block',
        'block.system_powered_by_block',
      ],
    ],
    '8150c8fa-26e9-403c-8225-852a32490c35' => [
      'name' => 'Atom/Text',
      'id' => '8150c8fa-26e9-403c-8225-852a32490c35',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.heading',
        'sdc.xb_test_sdc.shoe_badge',
      ],
    ],
    '8bacc99f-b9b8-418b-b14a-db564364592d' => [
      'name' => 'core',
      'id' => '8bacc99f-b9b8-418b-b14a-db564364592d',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.local_actions_block',
        'block.local_tasks_block',
        'block.page_title_block',
      ],
    ],
    '912ee056-75f7-490e-84ad-cc485e469f13' => [
      'name' => 'Menus',
      'id' => '912ee056-75f7-490e-84ad-cc485e469f13',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'block.system_menu_block.account',
        'block.system_menu_block.admin',
        'block.system_menu_block.footer',
        'block.system_menu_block.main',
        'block.system_menu_block.tools',
      ],
    ],
    'cf03636e-6f4f-4cef-992d-bcf319a7cb69' => [
      'name' => 'Other',
      'id' => 'cf03636e-6f4f-4cef-992d-bcf319a7cb69',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_broken_sdcs.invalid-filter',
        'sdc.xb_test_sdc.my-cta',
        'sdc.xb_test_sdc.component-no-meta-enum',
        'sdc.xb_test_sdc.card',
        'sdc.xb_test_sdc.props-no-slots',
        'sdc.xb_test_sdc.image-required-with-example',
        'sdc.xb_test_sdc.card-with-stream-wrapper-image',
        'sdc.xb_test_sdc.my-hero',
        'sdc.xb_test_sdc.props-slots',
        'sdc.xb_test_sdc.my-section',
        'sdc.xb_test_sdc.crash',
        'sdc.xb_test_sdc.card-with-local-image',
        'sdc.xb_test_sdc.image-optional-with-example',
        'sdc.xb_test_sdc.image',
        'sdc.xb_test_sdc.attributes',
        'sdc.xb_test_sdc.image-optional-with-example-and-additional-prop',
        'sdc.xb_test_sdc.sparkline',
        'sdc.xb_test_sdc.image-optional-without-example',
        'sdc.xb_test_sdc.card-with-remote-image',
        'sdc.xb_test_sdc.image-gallery',
        'sdc.xb_test_sdc.druplicon',
      ],
    ],
    'd0ba87b2-79b4-4622-98e1-cf82dc3655a0' => [
      'name' => 'Container',
      'id' => 'd0ba87b2-79b4-4622-98e1-cf82dc3655a0',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.grid-container',
        'sdc.xb_test_sdc.one_column',
        'sdc.xb_test_sdc.two_column',
      ],
    ],
    'd64b91c6-f99e-43fc-b251-777c7e2f4669' => [
      'name' => 'Atom/Tabs',
      'id' => 'd64b91c6-f99e-43fc-b251-777c7e2f4669',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.shoe_tab_panel',
        'sdc.xb_test_sdc.shoe_tab',
      ],
    ],
    'e3fac676-8929-4205-abe8-df94ec85e0d2' => [
      'name' => 'Status',
      'id' => 'e3fac676-8929-4205-abe8-df94ec85e0d2',
      'type' => 'component',
      'weight' => 0,
      'items' => [
        'sdc.xb_test_sdc.experimental',
        'sdc.xb_test_sdc.deprecated',
      ],
    ],
  ];

  protected readonly UserInterface $limitedPermissionsUser;

  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser([
      'administer themes',
      Page::EDIT_PERMISSION,
      Component::ADMIN_PERMISSION,
      JavaScriptComponent::ADMIN_PERMISSION,
      Pattern::ADMIN_PERMISSION,
      Folder::ADMIN_PERMISSION,
    ]);
    assert($user instanceof UserInterface);
    $this->httpApiUser = $user;

    // Create a user with an arbitrary permission that is not related to
    // accessing any XB resources.
    $user2 = $this->createUser(['view media']);
    assert($user2 instanceof UserInterface);
    $this->limitedPermissionsUser = $user2;
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
    $this->drupalLogin($this->limitedPermissionsUser);
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
    $this->drupalLogin($this->limitedPermissionsUser);
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
      'dataDependencies' => [],
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
        'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
      'dataDependencies' => [],
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
    $this->drupalLogin($this->limitedPermissionsUser);
    $this->assertAuthenticationAndAuthorization(AssetLibrary::ENTITY_TYPE_ID, FALSE);

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

  private function assertAuthenticationAndAuthorization(string $entity_type_id, bool $delete_allowed = TRUE, array $initial_items = []): void {
    $list_url = Url::fromUri("base:/xb/api/v0/config/$entity_type_id");

    // Insufficient Permissions: 403.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 403, ['user.permissions'], ['4xx-response', 'http_response'], 'UNCACHEABLE (request policy)', NULL);
    $this->assertSame([
      'errors' => [
        'Requires >=1 content entity type with an XB field that can be created or edited.',
      ],
    ], $body);

    // Authenticated & authorized: 200, but empty list.
    $this->drupalLogin($this->httpApiUser);
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ["config:{$entity_type_id}_list", 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    if (empty($initial_items)) {
      $this->assertSame([], $body);
    }
    else {
      $this->assertSameFoldersSansUuids($initial_items, $body ?? []);
    }

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
    $config_entity_type_definition = $this->container->get(EntityTypeManagerInterface::class)->getDefinition($entity_type_id);
    assert($config_entity_type_definition instanceof ConfigEntityType);
    $idKey = $config_entity_type_definition->get('xb_client_id_key') ?? $config_entity_type_definition->getKey('id');
    $this->assertArrayHasKey($idKey, $body);
    $id = $body[$idKey];

    $this->drupalLogout();
    // Verify we cannot access it as anonymous.
    $canonical_url = Url::fromUri("base:/xb/api/v0/config/$entity_type_id/$id");
    $response = $this->makeApiRequest('GET', $canonical_url, []);
    $this->assertSame(401, $response->getStatusCode());

    $this->drupalLogin($this->limitedPermissionsUser);
    // Verify we cannot access it with insufficient permissions.
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
      'user:3',
      'user_list',
    ];
    // If expected adds new components, those components add additional cache tags. If those cache tags are not
    // present, the test will fail. This array is used to add those additional expected cache tags.
    $expected_cache_tags = \array_values(Cache::mergeTags($expected_cache_tags, \array_values($additional_expected_cache_tags)));
    $body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/v0/config/component'), $request_options, 200, $expected_contexts, $expected_cache_tags, 'UNCACHEABLE (request policy)', $expected_dynamic_page_cache);
    self::assertNotNull($body);
    $component_config_entity_ids = array_keys($body);
    self::assertSame(
      $expected,
      array_values(array_filter($component_config_entity_ids, fn (string $id) => str_starts_with($id, 'js.'))),
    );
  }

  public function testComponent(): void {
    $this->container->get('theme_installer')->install(['stark', 'test_theme_child']);

    // Ensure we have an interesting set of Component config entities: the ones
    // provided by the modules & themes, including:
    self::assertNotEmpty(Component::loadMultiple());
    // - one that (intentionally) fails to render
    self::assertInstanceOf(ComponentInterface::class, Component::load('sdc.xb_broken_sdcs.invalid-filter'));
    // - one that was installed but explicitly disabled.
    self::assertTrue(Component::load('block.system_menu_block.tools')?->status());
    Component::load('block.system_menu_block.tools')->disable()->save();
    self::assertFalse(Component::load('block.system_menu_block.tools')->status());
    // - one that does not originate from any extension, but is a code component
    //   created from scratch and exposed as a Component
    $this->createMyCtaComponentFromSdc();

    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->httpApiUser);
    $this->drupalGet('xb/api/v0/config/component');

    $expected_tags = [
      'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form',
      'config:component_list',
      'config:core.extension',
      'config:experience_builder.js_component.my-cta',
      'config:system.menu.account',
      'config:system.menu.admin',
      'config:system.menu.footer',
      'config:system.menu.main',
      'config:system.site',
      'config:system.theme',
      'config:views.view.content_recent',
      'config:views.view.who_s_new',
      'http_response',
      'local_task',
      'node_list',
      'user:1',
      'user:2',
      'user:3',
      'user_list',
      AutoSaveManager::CACHE_TAG,
    ];

    $expected_contexts = [
      'languages:language_content',
      'route',
      'url.path',
      'url.query_args',
      'user.node_grants:view',
      'user.roles:authenticated',
      // The user_login_block is rendered as the anonymous user because for the
      // authenticated user it is empty.
      // @see \Drupal\experience_builder\Controller\ApiComponentsController::getCacheableClientSideInfo()
      'user.roles:anonymous',
    ];

    // 1. Test basic functionality.
    $this->assertSame(200, $this->getSession()->getStatusCode(), match($this->getSession()->getStatusCode()) {
      // Show the fatal error message in the failing test output.
      // @see \Drupal\experience_builder\EventSubscriber\ApiExceptionSubscriber
      500 => json_decode($page->getContent())->message,
      default => $page->getContent(),
    });
    $this->assertCacheTags($expected_tags, FALSE);
    $this->assertCacheContexts($expected_contexts);
    $this->assertDynamicPageCacheAccelerated(maxAge: '-1 (Permanent)');
    $data = Json::decode($page->getText());
    self::assertGreaterThanOrEqual(45, count($data));
    // Any `noUi`-flagged SDC does not appear.
    self::assertArrayNotHasKey('sdc.xb_test_sdc.no-ui-sdc', $data);
    // The disabled block component does not appear.
    self::assertArrayNotHasKey('block.system_menu_block.tools', $data);
    // The freshly created code component does appear.
    self::assertArrayHasKey('js.my-cta', $data);

    // 2. Test results depending on the default theme.
    // Stark has no SDCs.
    $this->assertSame('stark', $this->config('system.theme')->get('default'));
    $this->assertArrayNotHasKey('sdc.test_theme_child.test-child', $data);
    // Test Theme Child does have an SDC, and it's enabled, but it is omitted because the
    // default theme is Stark.
    $this->assertInstanceOf(Component::class, Component::load('sdc.test_theme_child.test-child'));
    $this->assertTrue(Component::load('sdc.test_theme_child.test-child')->status());
    $this->assertSame('test_theme_child', Component::load('sdc.test_theme_child.test-child')->get('provider'));
    // Change the default theme from Stark to Test Theme Child, and observe the
    // impact on the list of Components returned.
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'test_theme_child')->save();
    $this->rebuildAll();
    $this->drupalGet('xb/api/v0/config/component');
    $this->assertDynamicPageCacheAccelerated(maxAge: '-1 (Permanent)');
    $data = Json::decode($page->getText());
    // Test Theme Child does have an SDC!
    $this->assertSame('test_theme_child', $this->config('system.theme')->get('default'));
    $this->assertArrayHasKey('sdc.test_theme_child.test-child', $data);
    // Also verify that the Components from the base themes are available.
    $this->assertArrayHasKey('sdc.test_theme_base.test-base', $data);

    // 3. Test that good cacheability is guaranteed.
    // As soon as the "recent content" block has any nodes to list, due to its
    // use of the `timestamp_ago` formatter, its cacheability is too low to be
    // acceptable for Dynamic Page Cache.
    // Due to the performance-critical nature of this particular route, and it
    // being acceptable that previews of components do NOT have the same
    // freshness requirements, the server-side logic should impose a minimum
    // cache life time of 1 hour.
    // @see \Drupal\experience_builder\Controller\ApiConfigControllers::list()
    // @see https://www.drupal.org/project/experience_builder/issues/3484671#comment-15848590
    self::assertStringContainsString('No content available.', \json_decode($page->getContent(), TRUE)['block.views_block.content_recent-block_1']['default_markup']);
    $random = new Random();
    Node::create([
      'type' => 'article',
      'title' => 'Jack is ' . $random->word(10),
    ])->save();
    $this->drupalGet('xb/api/v0/config/component');
    $this->assertDynamicPageCacheAccelerated(maxAge: '3600');
    $this->assertCacheTags(Cache::mergeTags($expected_tags, ['node:1']), FALSE);
    $this->assertCacheContexts($expected_contexts);
    $recent_content_preview = \json_decode($page->getContent(), TRUE)['block.views_block.content_recent-block_1']['default_markup'];
    self::assertStringNotContainsString('No content available.', $recent_content_preview);
    self::assertStringContainsString('Jack', $recent_content_preview);
    self::assertStringContainsString('seconds ago', $recent_content_preview);
  }

  private function assertDynamicPageCacheAccelerated(?string $maxAge = NULL): void {
    // Ensure the response is cached by Dynamic Page Cache (because this is a
    // complex response), but not by Page Cache (because it should not be
    // available to anonymous users).
    if ($maxAge) {
      $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', $maxAge);
    }
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');
    $this->getSession()->reload();
    if ($maxAge) {
      $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', $maxAge);
    }
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'HIT');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'UNCACHEABLE (request policy)');
  }

  /**
   * @see \Drupal\experience_builder\Entity\Folder
   */
  public function testFolder(): void {
    $this->drupalLogin($this->limitedPermissionsUser);
    $this->assertAuthenticationAndAuthorization('folder', initial_items: $this->defaultFolders);

    $list_url = Url::fromUri("base:/xb/api/v0/config/folder");

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Create a Folder via the XB HTTP API, but forget crucial data that causes
    // the required shape to be violated: 500, courtesy of OpenAPI.
    $folder_to_send = [
      'name' => 'Test folder, please ignore',
      'type' => Component::ENTITY_TYPE_ID,
      'weight' => 0,
    ];
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/folder]. [Keyword validation failed: Required property \'items\' must be present in the object in items]',
    ], $body, 'Fails with missing data.');

    // Add missing crucial data, but leave a required shape violation: 500,
    // courtesy of OpenAPI.
    $folder_to_send['items'] = [
      1,
    ];
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [post /xb/api/v0/config/folder]. [Value expected to be \'string\', but \'integer\' given in items->0]',
    ], $body, 'Fails with invalid shape.');

    // Meet data shape requirements, but violate constraint in `items`
    $folder_to_send['items'] = ['fake_component'];
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The \'experience_builder.component.fake_component\' config does not exist.',
          'source' => ['pointer' => 'items.0'],
        ],
      ],
    ], $body);

    // Re-retrieve list: 200, unchanged.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:folder_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertSameFoldersSansUuids($this->defaultFolders, $body ?? []);

    // Re-retrieve list: 200, unchanged, but now is a Dynamic Page Cache hit.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:folder_list', 'http_response'], 'UNCACHEABLE (request policy)', 'HIT');
    $this->assertSameFoldersSansUuids($this->defaultFolders, $body ?? []);

    // Create a Folder via the XB HTTP API, correctly: 201.
    $folder_to_send['items'] = [];
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL);
    assert(is_array($body));
    ksort($folder_to_send);
    ksort($body);
    $new_folder = Folder::loadByNameAndConfigEntityTypeId($folder_to_send['name'], $folder_to_send['type']);
    assert($new_folder instanceof Folder);
    $id = $new_folder->id();
    $this->assertEquals($folder_to_send + ['id' => $id], $body);

    // Creating a Folder with an already-in-use name: 422.
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'Name <em class="placeholder">Test folder, please ignore</em> is not unique in Folder type "<em class="placeholder">component</em>"',
          'source' => ['pointer' => 'name'],
        ],
      ],
    ], $body);

    // Create a Folder with BE generated id: 201.
    $new_folder_to_send = $folder_to_send;
    $new_folder_to_send['name'] = 'Unique test name, please ignore.';
    // Create folder with weight of -1 to place at the bottom of the list.
    $new_folder_to_send['weight'] = -1;
    $request_options[RequestOptions::JSON] = $new_folder_to_send;
    $body = $this->assertExpectedResponse('POST', $list_url, $request_options, 201, NULL, NULL, NULL, NULL);
    assert(is_array($body));
    $this->assertArrayHasKey('id', $body);
    $this->assertNotEquals($body['id'], $id);
    $this->assertTrue(Uuid::isValid($body['id']));
    $new_folder_id = $body['id'];

    // Create folder with weight of 1 to place at the bottom of the list.
    $temp_folder = Folder::create([
      'name' => 'Temp Folder',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
      'weight' => 1,
      'items' => [],
    ]);
    $temp_folder->save();

    // Fetch list of Folders to verify correct they are sorted correctly.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:folder_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    assert(is_array($body));
    $this->assertCount(count($this->defaultFolders) + 3, $body);
    $this->assertEquals($new_folder_id, array_keys($body)[0]);
    $this->assertEquals($temp_folder->id(), array_keys($body)[count($body) - 1]);
    $temp_folder->delete();

    // Delete Folder via the XB HTTP API: 204.
    $this->assertExpectedResponse('DELETE', Url::fromUri('base:/xb/api/v0/config/folder/' . $new_folder_id), [], 204, NULL, NULL, NULL, NULL);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    // Use the individual URL in the list response body.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:folder_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    self::assertNotNull($body);
    $this->assertEquals(array_keys(Folder::loadMultiple()), array_keys($body));
    $this->assertArrayHasKey($id, $body);
    $this->assertEquals($folder_to_send + ['id' => $id], $body[$id]);
    $individual_body = $this->assertExpectedResponse('GET', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), [], 200, ['user.permissions'], ['config:experience_builder.folder.' . $id, 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    $this->assertEquals($folder_to_send + ['id' => $id], $individual_body);

    // Modify a Folder incorrectly (shape-wise): 500.
    $request_options[RequestOptions::JSON] = [
      'id' => $id,
      'weight' => 0,
      'items' => NULL,
      'name' => 'Test',
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/v0/config/folder/{configEntityId}]. [Keyword validation failed: Value cannot be null in items]',
    ], $body, 'Fails with an invalid \'items\' value.');

    $request_options[RequestOptions::JSON] = [
      'id' => $id,
      'weight' => 0,
      'name' => NULL,
      'items' => [],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $request_options, 500, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'message' => 'Body does not match schema for content-type "application/json" for Request [patch /xb/api/v0/config/folder/{configEntityId}]. [Keyword validation failed: Value cannot be null in name]',
    ], $body, 'Fails with an invalid \'name\' value.');

    // Modify a Folder incorrectly (items constraint validation fail): 422.
    $request_options[RequestOptions::JSON] = [
      'id' => $id,
      'weight' => 0,
      'name' => 'Test',
      'items' => ['fake_component'],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $request_options, 422, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        [
          'detail' => 'The \'experience_builder.component.fake_component\' config does not exist.',
          'source' => ['pointer' => 'items.0'],
        ],
      ],
    ], $body);

    // Modify a Folder correctly: 200.
    $request_options[RequestOptions::JSON] = $folder_to_send;
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertEquals($folder_to_send + ['id' => $id], $body);

    // Partially modify a Folder: 200.
    $folder_to_send['name'] = 'Updated test Folder name';
    $request_options[RequestOptions::JSON] = [
      'name' => $folder_to_send['name'],
      'weight' => $folder_to_send['weight'],
      'items' => $folder_to_send['items'],
    ];
    $body = $this->assertExpectedResponse('PATCH', Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $request_options, 200, NULL, NULL, NULL, NULL);
    $this->assertEquals($folder_to_send + ['id' => $id], $body);

    // Re-retrieve list: 200, non-empty list. Dynamic Page Cache miss.
    $body = $this->assertExpectedResponse('GET', $list_url, [], 200, ['user.permissions'], ['config:folder_list', 'http_response'], 'UNCACHEABLE (request policy)', 'MISS');
    self::assertNotNull($body);
    $this->assertEquals(array_keys(Folder::loadMultiple()), array_keys($body));
    $this->assertArrayHasKey($id, $body);
    $this->assertEquals($folder_to_send + ['id' => $id], $body[$id]);

    // Delete the recently added Folder via the XB HTTP API: 204.
    $folders_with_a_delete = $this->defaultFolders;
    unset($folders_with_a_delete[$id]);
    $this->assertDeletionAndEmptyList(Url::fromUri('base:/xb/api/v0/config/folder/' . $id), $list_url, 'config:folder_list', $folders_with_a_delete);

    // This was now tested full circle! ✅
  }

}
