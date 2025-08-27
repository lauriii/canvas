<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group experience_builder
 */
final class ApiUiContentTemplateControllersTest extends HttpApiTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'node',
    'xb_test_sdc',
    'xb_test_code_components',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $limitedPermissionsUser;

  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    $this->createContentType(['type' => 'article', 'name' => 'Article']);

    // Required, single-cardinality image field.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'type' => 'image',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_silly_image',
      'bundle' => 'article',
      'required' => TRUE,
    ])->save();

    // Required, multiple-cardinality image field.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_screenshots',
      'type' => 'image',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_screenshots',
      'bundle' => 'article',
      'required' => TRUE,
    ])->save();

    // Optional, single-cardinality user profile picture field.
    FieldStorageConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'type' => 'image',
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'label' => 'User Picture',
      'description' => '',
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'required' => FALSE,
    ])->save();

    $account = $this->createUser([
      ContentTemplate::ADMIN_PERMISSION,
    ]);
    \assert($account instanceof UserInterface);
    $this->drupalLogin($account);

    $user2 = $this->createUser(['view media']);
    assert($user2 instanceof UserInterface);
    $this->limitedPermissionsUser = $user2;
  }

  /**
   * @dataProvider providerSuggestions
   * @see \Drupal\Tests\experience_builder\Kernel\FieldForComponentSuggesterTest
   */
  public function testSuggestions(string $component_config_entity_id, string $content_entity_type_id, string $bundle, array $expected): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/xb/api/v0/ui/content_template/suggestions/structured-data-for-prop_shapes/$content_entity_type_id/$bundle/$component_config_entity_id"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );
    $this->assertSame($expected, $json);
  }

  public static function providerSuggestions(): \Generator {
    $choice_article_title = [
      'label' => "This Article's Title",
      'source' => ['sourceType' => 'dynamic', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'],
    ];
    $choice_article_image = [
      'label' => "Subset of this Article's field_silly_image: src_with_alternate_widths, alt, width, height (4 of 7 props — absent: entity, title, srcset_candidate_uri_template)",
      'source' => ['sourceType' => 'dynamic', 'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}'],
    ];
    $hash_for_choice = fn (array $choice) =>  \hash('xxh64', $choice['source']['expression']);

    yield 'a simple primitive example (sdc.xb_test_sdc.heading, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.heading',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'text' => [
          $hash_for_choice($choice_article_title) => $choice_article_title,
        ],
        'style' => [],
        'element' => [],
      ],
    ];
    yield 'a simple primitive example (sdc.xb_test_sdc.heading, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.heading',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'text' => [],
        'style' => [],
        'element' => [],
      ],
    ];

    yield 'a propless example (sdc.xb_test_sdc.druplicon, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.druplicon',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [],
    ];
    yield 'a propless example (sdc.xb_test_sdc.druplicon, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.druplicon',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [],
    ];

    yield 'a simple object example (sdc.xb_test_sdc.image-required-with-example, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.image-required-with-example',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'image' => [
          $hash_for_choice($choice_article_image) => $choice_article_image,
        ],
      ],
    ];
    yield 'an OPTIONAL simple object example (sdc.xb_test_sdc.image-optional-with-example, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.image-optional-with-example',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'image' => [
          $hash_for_choice($choice_article_image) => $choice_article_image,
        ],
      ],
    ];
    yield 'a simple object example (sdc.xb_test_sdc.image-required-with-example, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.image-required-with-example',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'image' => [],
      ],
    ];
    yield 'an OPTIONAL simple object example (sdc.xb_test_sdc.image-optional-with-example, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.image-optional-with-example',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'image' => [
          // @todo This SHOULD find the `user_picture` field, fix in https://www.drupal.org/project/experience_builder/issues/3541361
        ],
      ],
    ];

    yield 'an array of object values example (sdc.xb_test_sdc.image-gallery, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.xb_test_sdc.image-gallery',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'caption' => [
          '82ec95693bc89080' => [
            'label' => "Subset of this Article's field_silly_image: alt (1 of 7 props — absent: entity, title, width, height, srcset_candidate_uri_template, src_with_alternate_widths)",
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟alt',
            ],
          ],
          '1409e675864fd2e6' => [
            'label' => "Subset of this Article's field_silly_image: title (1 of 7 props — absent: entity, alt, width, height, srcset_candidate_uri_template, src_with_alternate_widths)",
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟title',
            ],
          ],
          '7ca10058b43f4d0f' => [
            'label' => "This Article's Revision log message",
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝revision_log␞␟value',
            ],
          ],
          $hash_for_choice($choice_article_title) => $choice_article_title,
        ],
        'images' => [
          '441f35fe6e2feefd' => [
            'label' => "Subset of this Article's field_screenshots: src_with_alternate_widths, alt, width, height (4 of 7 props — absent: entity, title, srcset_candidate_uri_template)",
            "source" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * @testWith ["a/b/c", 404, "The component c does not exist."]
   *           ["a/b/sdc.xb_test_sdc.image", 404, "The `a` content entity type does not exist."]
   *           ["node/b/sdc.xb_test_sdc.image", 404, "The `node` content entity type does not have a `b` bundle."]
   *           ["node/article/block.user_login_block", 400, "Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported."]
   *           ["node/article/js.xb_test_code_components_with_link_prop", 400, "Code components are not supported yet."]
   *           ["node/article/js.xb_test_code_components_with_no_props", 400, "Code components are not supported yet."]
   */
  public function testSuggestionsClientErrors(string $trail, int $expected_status_code, string $expected_error_message): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/xb/api/v0/ui/content_template/suggestions/structured-data-for-prop_shapes/' . $trail),
      request_options: [],
      expected_status: $expected_status_code,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );
    $this->assertSame(['errors' => [$expected_error_message]], $json);

    // When performing the same request without the necessary permission,
    // expect a 403 with a message stating which permission is needed.
    // Testing this for each client error case proves no information is divulged
    // to unauthorized requests. Note also that Page Cache accelerates these.
    $this->drupalLogin($this->limitedPermissionsUser);
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/xb/api/v0/ui/content_template/suggestions/structured-data-for-prop_shapes/' . $trail),
      request_options: [],
      expected_status: Response::HTTP_FORBIDDEN,
      expected_cache_contexts: ['user.permissions'],
      expected_cache_tags: ['4xx-response', 'http_response'],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: NULL,
    );
    $this->assertSame(['errors' => [sprintf("The '%s' permission is required.", ContentTemplate::ADMIN_PERMISSION)]], $json);
  }

}
