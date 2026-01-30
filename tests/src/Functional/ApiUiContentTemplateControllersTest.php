<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group canvas
 */
final class ApiUiContentTemplateControllersTest extends HttpApiTestBase {

  use GenerateComponentConfigTrait;
  use OpenApiSpecTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'node',
    'canvas_test_sdc',
    'canvas_test_code_components',
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
      'label' => 'Silly image 🤡',
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
    // @see core/profiles/standard/config/install/field.storage.user.user_picture.yml
    FieldStorageConfig::create([
      'entity_type' => 'user',
      'field_name' => 'user_picture',
      'type' => 'image',
      'translatable' => FALSE,
      'cardinality' => 1,
    ])->save();
    // @see core/profiles/standard/config/install/field.field.user.user.user_picture.yml
    FieldConfig::create([
      'label' => 'Picture',
      'description' => '',
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'bundle' => 'user',
      'required' => FALSE,
    ])->save();

    // Optional, multiple-cardinality tags field.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_tags',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_tags',
      'label' => 'Tags',
      'bundle' => 'article',
      'required' => FALSE,
    ])->save();

    // Set explicitly the form display components to ensure the suggestions
    // sorting is as expected.
    $form_display = \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article');
    $weight = 10;
    foreach (['field_silly_image', 'uid', 'field_screenshots', 'user_picture', 'field_tags'] as $form_display_component_id) {
      $form_component = $form_display->getComponent($form_display_component_id);
      $form_component['weight'] = $weight;
      $form_display->setComponent($form_display_component_id, $form_component);
      $weight += 5;
    }
    $form_display->save();

    $account = $this->createUser([
      ContentTemplate::ADMIN_PERMISSION,
      'edit any article content',
      'view own unpublished content',
    ]);
    \assert($account instanceof UserInterface);
    $this->drupalLogin($account);

    $user2 = $this->createUser(['view media']);
    \assert($user2 instanceof UserInterface);
    $this->limitedPermissionsUser = $user2;
  }

  /**
   * @dataProvider providerSuggestPropSources
   * @see \Drupal\Tests\canvas\Kernel\PropSourceSuggesterTest
   */
  public function testSuggestPropSources(string $component_config_entity_id, string $content_entity_type_id, string $bundle, array $expected): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/$content_entity_type_id/$bundle/$component_config_entity_id"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );
    $this->assertSame($expected, $json);
  }

  public static function providerSuggestPropSources(): \Generator {
    $choice_article_title = [
      'source' => ['sourceType' => 'dynamic', 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'],
      'label' => "Title",
    ];
    $choice_article_image = [
      'source' => ['sourceType' => 'dynamic', 'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}'],
      'label' => "Silly image 🤡",
    ];
    $choice_article_author_name = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'label' => 'Name',
    ];
    $choice_article_author_picture_alt = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      'label' => 'Alternative text',
    ];
    $choice_article_author_picture_title = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
      ],
      'label' => 'Title',
    ];
    $choice_article_revision_user_name = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'label' => 'Name',
    ];
    $choice_article_revision_user_picture_alt = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      'label' => 'Alternative text',
    ];
    $choice_article_revision_user_picture_title = [
      'source' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟title',
      ],
      'label' => 'Title',
    ];
    $hash_for_choice = fn (array $choice) =>  \hash('xxh64', $choice['source']['expression']);

    yield 'a simple primitive example (sdc.canvas_test_sdc.heading, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.heading',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'text' => [
          ['id' => $hash_for_choice($choice_article_title)] + $choice_article_title,
        ],
        'style' => [],
        'element' => [],
      ],
    ];
    yield 'a simple primitive example (sdc.canvas_test_sdc.heading, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.heading',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'text' => [
          [
            'id' => '67f45d35294a49e0',
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝name␞␟value',
            ],
            'label' => 'Name',
          ],
        ],
        'style' => [],
        'element' => [],
      ],
    ];

    yield 'a propless example (sdc.canvas_test_sdc.druplicon, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.druplicon',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [],
    ];
    yield 'a propless example (sdc.canvas_test_sdc.druplicon, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.druplicon',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [],
    ];

    yield 'a simple object example (sdc.canvas_test_sdc.image-required-with-example, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.image-required-with-example',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'image' => [
          ['id' => $hash_for_choice($choice_article_image)] + $choice_article_image,
        ],
      ],
    ];
    yield 'an OPTIONAL simple object example (sdc.canvas_test_sdc.image-optional-with-example, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.image-optional-with-example',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'image' => [
          ['id' => $hash_for_choice($choice_article_image)] + $choice_article_image,
          [
            'items' => [
              [
                'items' => [
                  [
                    'id' => '0bded99fb661deb7',
                    'source' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Authored by',
          ],
          [
            'items' => [
              [
                'items' => [
                  [
                    'id' => '32b7fa7b2bad34a6',
                    'source' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Revision user',
          ],
        ],
      ],
    ];
    yield 'a simple object example (sdc.canvas_test_sdc.image-required-with-example, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.image-required-with-example',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'image' => [],
      ],
    ];
    yield 'an OPTIONAL simple object example (sdc.canvas_test_sdc.image-optional-with-example, entity:user:user)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.image-optional-with-example',
      'content_entity_type_id' => 'user',
      'bundle' => 'user',
      'expected' => [
        'image' => [
          [
            'id' => '57e3db5a8919b50e',
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'label' => 'Picture',
          ],
        ],
      ],
    ];

    yield 'an OPTIONAL array of strings example (sdc.canvas_test_sdc.tags, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.tags',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'tags' => [
          [
            'items' => [
              [
                'id' => '6f972dac9b3e8954',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟alt',
                ],
                'label' => 'Alternative text',
              ],
              [
                'id' => '1138e38cc9e6b7dd',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟title',
                ],
                'label' => 'Title',
              ],
            ],
            'label' => 'field_screenshots',
          ],
          [
            'items' => [
              [
                'items' => [
                  [
                    'id' => '563f6a4e0001da4c',
                    'source' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:node␝title␞␟value',
                    ],
                    'label' => 'Title',
                  ],
                ],
                'label' => 'Content',
              ],
            ],
            'label' => 'Tags',
          ],
        ],
      ],
    ];

    yield 'an array of object values example (sdc.canvas_test_sdc.image-gallery, entity:node:article)' => [
      'component_config_entity_id' => 'sdc.canvas_test_sdc.image-gallery',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'caption' => [
          ['id' => $hash_for_choice($choice_article_title)] + $choice_article_title,
          [
            'items' => [
              [
                'id' => '82ec95693bc89080',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟alt',
                ],
                'label' => "Alternative text",
              ],
              [
                'id' => '1409e675864fd2e6',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟title',
                ],
                'label' => "Title",
              ],
            ],
            'label' => 'Silly image 🤡',
          ],
          [
            'items' => [
              [
                'items' => [
                  ['id' => $hash_for_choice($choice_article_author_name)] + $choice_article_author_name,
                  [
                    'items' => [
                      ['id' => $hash_for_choice($choice_article_author_picture_alt)] + $choice_article_author_picture_alt,
                      ['id' => $hash_for_choice($choice_article_author_picture_title)] + $choice_article_author_picture_title,
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Authored by',
          ],
          [
            'items' => [
              [
                'items' => [
                  ['id' => $hash_for_choice($choice_article_revision_user_name)] + $choice_article_revision_user_name,
                  [
                    'items' => [
                      ['id' => $hash_for_choice($choice_article_revision_user_picture_alt)] + $choice_article_revision_user_picture_alt,
                      ['id' => $hash_for_choice($choice_article_revision_user_picture_title)] + $choice_article_revision_user_picture_title,
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Revision user',
          ],
        ],
        'images' => [
          [
            'id' => '441f35fe6e2feefd',
            "source" => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            ],
            'label' => "field_screenshots",
          ],
        ],
      ],
    ];

    yield 'a simple code component with link prop (js.canvas_test_code_components_with_link_prop, entity:node:article)' => [
      'component_config_entity_id' => 'js.canvas_test_code_components_with_link_prop',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [
        'text' => [
          ['id' => $hash_for_choice($choice_article_title)] + $choice_article_title,
          [
            'items' => [
              [
                'id' => '82ec95693bc89080',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟alt',
                ],
                'label' => "Alternative text",
              ],
              [
                'id' => '1409e675864fd2e6',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟title',
                ],
                'label' => "Title",
              ],
            ],
            'label' => 'Silly image 🤡',
          ],
          [
            'items' => [
              [
                'items' => [
                  ['id' => $hash_for_choice($choice_article_author_name)] + $choice_article_author_name,
                  [
                    'items' => [
                      ['id' => $hash_for_choice($choice_article_author_picture_alt)] + $choice_article_author_picture_alt,
                      ['id' => $hash_for_choice($choice_article_author_picture_title)] + $choice_article_author_picture_title,
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Authored by',
          ],
          [
            'items' => [
              [
                'items' => [
                  ['id' => $hash_for_choice($choice_article_revision_user_name)] + $choice_article_revision_user_name,
                  [
                    'items' => [
                      ['id' => $hash_for_choice($choice_article_revision_user_picture_alt)] + $choice_article_revision_user_picture_alt,
                      ['id' => $hash_for_choice($choice_article_revision_user_picture_title)] + $choice_article_revision_user_picture_title,
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
            ],
            'label' => 'Revision user',
          ],
        ],
        'link' => [
          [
            'id' => '4999dcb72722c69a',
            'source' => [
              'sourceType' => 'dynamic',
              'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟src_with_alternate_widths',
            ],
            'label' => 'Silly image 🤡',
          ],
          [
            'items' => [
              [
                'items' => [
                  [
                    'id' => '134a8de6cbb83338',
                    'source' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
              [
                'id' => '40aec6943bb1f70a',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝uid␞␟url',
                ],
                'label' => 'URL',
              ],
            ],
            'label' => 'Authored by',
          ],
          [
            'items' => [
              [
                'items' => [
                  [
                    'id' => '5b16c0771fff7364',
                    'source' => [
                      'sourceType' => 'dynamic',
                      'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟src_with_alternate_widths',
                    ],
                    'label' => 'Picture',
                  ],
                ],
                'label' => 'User',
              ],
              [
                'id' => 'f406165063d98f55',
                'source' => [
                  'sourceType' => 'dynamic',
                  'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟url',
                ],
                'label' => 'URL',
              ],
            ],
            'label' => 'Revision user',
          ],
          [
            'id' => '51af7eb3ee57c3a5',
            'source' => [
              'sourceType' => 'host-entity-url',
              'absolute' => FALSE,
            ],
            'label' => 'Relative URL',
          ],
        ],
      ],
    ];

    yield 'a simple code component with no props (js.canvas_test_code_components_with_no_props, entity:node:article)' => [
      'component_config_entity_id' => 'js.canvas_test_code_components_with_no_props',
      'content_entity_type_id' => 'node',
      'bundle' => 'article',
      'expected' => [],
    ];
  }

  /**
   * @testWith ["a/b/c", 404, "The component c does not exist."]
   *           ["a/b/sdc.canvas_test_sdc.image", 404, "The `a` content entity type does not exist."]
   *           ["node/b/sdc.canvas_test_sdc.image", 404, "The `node` content entity type does not have a `b` bundle."]
   *           ["node/article/block.user_login_block", 400, "Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported."]
   */
  public function testSuggestPropSourcesClientErrors(string $trail, int $expected_status_code, string $expected_error_message): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/' . $trail),
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
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/' . $trail),
      request_options: [],
      expected_status: Response::HTTP_FORBIDDEN,
      expected_cache_contexts: ['user.permissions'],
      expected_cache_tags: ['4xx-response', 'http_response'],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: NULL,
    );
    $this->assertSame(['errors' => [\sprintf("The '%s' permission is required.", ContentTemplate::ADMIN_PERMISSION)]], $json);
  }

  public function testSuggestPreviewContentEntities(): void {
    $content_entity_type_id = 'node';
    $bundle = 'article';

    // There are no entities, so we get an empty list.
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user.node_grants:view',
        'user.permissions',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'MISS',
    );
    $this->assertSame([], $json);

    // As soon as we create some, we are going to return those.
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($content_entity_type_id);
    for ($i = 1; $i <= 5; ++$i) {
      $entity_storage->create([
        'title' => 'Entity ' . $i,
        'type' => $bundle,
        'changed' => \time() - ($i * 15000),
      ])->save();
    }

    $expected = [
      1 => ['id' => '1', 'label' => 'Entity 1'],
      2 => ['id' => '2', 'label' => 'Entity 2'],
      3 => ['id' => '3', 'label' => 'Entity 3'],
      4 => ['id' => '4', 'label' => 'Entity 4'],
      5 => ['id' => '5', 'label' => 'Entity 5'],
    ];
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user.node_grants:view',
        'user.permissions',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . ':1',
        $content_entity_type_id . ':2',
        $content_entity_type_id . ':3',
        $content_entity_type_id . ':4',
        $content_entity_type_id . ':5',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'MISS',
    );
    $this->assertSame($expected, $json);

    // Just because there is a new node doesn't MISS the cache and returns the new one.
    $entity_storage->create([
      'title' => 'Entity LAST',
      'type' => $bundle,
      'changed' => \time() - 5000,
    ])->save();
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user.node_grants:view',
        'user.permissions',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . ':1',
        $content_entity_type_id . ':2',
        $content_entity_type_id . ':3',
        $content_entity_type_id . ':4',
        $content_entity_type_id . ':5',
        $content_entity_type_id . ':6',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'MISS',
    );
    $expected = [6 => ['id' => '6', 'label' => 'Entity LAST']] + $expected;
    $this->assertSame($expected, $json);

    /** @var \Drupal\node\NodeInterface $updated_entity */
    $updated_entity = $entity_storage->load(3);
    $updated_entity->setTitle('Updated article')
      ->save();
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user.node_grants:view',
        'user.permissions',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . ':1',
        $content_entity_type_id . ':2',
        $content_entity_type_id . ':3',
        $content_entity_type_id . ':4',
        $content_entity_type_id . ':5',
        $content_entity_type_id . ':6',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'MISS',
    );
    $expected = [
      3 => ['id' => '3', 'label' => 'Updated article'],
      6 => ['id' => '6', 'label' => 'Entity LAST'],
      1 => ['id' => '1', 'label' => 'Entity 1'],
      2 => ['id' => '2', 'label' => 'Entity 2'],
      4 => ['id' => '4', 'label' => 'Entity 4'],
      5 => ['id' => '5', 'label' => 'Entity 5'],
    ];
    $this->assertSame($expected, $json);

    // Test with unpublished content entities - they should also appear in the list.
    $entity_storage->create([
      'title' => 'Unpublished Entity 1',
      'type' => $bundle,
      'status' => 0,
      'changed' => \time() + 5000,
    ])->save();
    $entity_storage->create([
      'title' => 'Unpublished Entity 2',
      'type' => $bundle,
      'status' => 0,
      'changed' => \time() + 10000,
    ])->save();
    // Test the 10-entity limit: create additional entities to exceed the limit.
    // The oldest entities (by changed time) should be excluded from the response.
    $entity_storage->create([
      'title' => 'New Entity 9',
      'type' => $bundle,
      'status' => 1,
      'changed' => \time() + 15000,
    ])->save();
    $entity_storage->create([
      'title' => 'New Entity 10',
      'type' => $bundle,
      'status' => 1,
      'changed' => \time() + 20000,
    ])->save();
    $entity_storage->create([
      'title' => 'New Entity 11',
      'type' => $bundle,
      'status' => 1,
      'changed' => \time() + 25000,
    ])->save();

    // Unpublished entities should be included in the response.
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . ':1',
        $content_entity_type_id . ':10',
        $content_entity_type_id . ':11',
        $content_entity_type_id . ':2',
        $content_entity_type_id . ':3',
        $content_entity_type_id . ':4',
        $content_entity_type_id . ':6',
        $content_entity_type_id . ':7',
        $content_entity_type_id . ':8',
        $content_entity_type_id . ':9',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (poor cacheability)',
    );
    // Expected should now include the unpublished nodes and exclude
    // the oldest ones to maintain the 10-entity limit.
    $expected = [
      11 => ['id' => '11', 'label' => 'New Entity 11'],
      10 => ['id' => '10', 'label' => 'New Entity 10'],
      9 => ['id' => '9', 'label' => 'New Entity 9'],
      8 => ['id' => '8', 'label' => 'Unpublished Entity 2'],
      7 => ['id' => '7', 'label' => 'Unpublished Entity 1'],
      3 => ['id' => '3', 'label' => 'Updated article'],
      6 => ['id' => '6', 'label' => 'Entity LAST'],
      1 => ['id' => '1', 'label' => 'Entity 1'],
      2 => ['id' => '2', 'label' => 'Entity 2'],
      4 => ['id' => '4', 'label' => 'Entity 4'],
    ];
    $this->assertSame($expected, $json);
  }

  /**
   * Tests that users without 'view own unpublished content' permission cannot see unpublished content.
   */
  public function testSuggestPreviewWithoutUnpublishedPermission(): void {
    $content_entity_type_id = 'node';
    $bundle = 'article';

    // Create a user WITHOUT 'view own unpublished content' permission.
    $user_without_permission = $this->createUser([
      ContentTemplate::ADMIN_PERMISSION,
      'edit any article content',
    ]);
    \assert($user_without_permission instanceof UserInterface);

    // Create published and unpublished content entities.
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($content_entity_type_id);

    // Create published entities.
    for ($i = 1; $i <= 3; ++$i) {
      $entity_storage->create([
        'title' => 'Published Entity ' . $i,
        'type' => $bundle,
        'status' => 1,
        'uid' => $user_without_permission->id(),
        'changed' => \time() - $i * 1000,
      ])->save();
    }

    // Create unpublished entities owned by the user.
    for ($i = 1; $i <= 2; ++$i) {
      $entity_storage->create([
        'title' => 'Unpublished Entity ' . $i,
        'type' => $bundle,
        'status' => 0,
        'uid' => $user_without_permission->id(),
        'changed' => \time() + $i * 1000,
      ])->save();
    }

    // Login as the user without 'view own unpublished content' permission.
    $this->drupalLogin($user_without_permission);

    // Request the preview suggestions.
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/preview/$content_entity_type_id/$bundle"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: [
        'user.node_grants:view',
        'user.permissions',
      ],
      expected_cache_tags: [
        'http_response',
        $content_entity_type_id . ':1',
        $content_entity_type_id . ':2',
        $content_entity_type_id . ':3',
        $content_entity_type_id . '_list:' . $bundle,
      ],
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'MISS',
    );

    // Only published entities should be returned,
    // unpublished entities 4 and 5 should NOT be included.
    $expected = [
      1 => ['id' => '1', 'label' => 'Published Entity 1'],
      2 => ['id' => '2', 'label' => 'Published Entity 2'],
      3 => ['id' => '3', 'label' => 'Published Entity 3'],
    ];
    $this->assertSame($expected, $json);
  }

  public function testViewModesList(): void {
    // 1. Test endpoint response when no Template entities are available.
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/view_modes/node'),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );

    // All View Modes for Article bundle are returned, no ContentTemplates exist.
    self::assertEquals([
      'node' => [
        'article' => [
          'teaser' => [
            'label' => 'Teaser',
            'hasTemplate' => FALSE,
          ],
          'full' => [
            'label' => 'Full content',
            'hasTemplate' => FALSE,
          ],
          'rss' => [
            'label' => 'RSS',
            'hasTemplate' => FALSE,
          ],
          'search_index' => [
            'label' => 'Search index',
            'hasTemplate' => FALSE,
          ],
          'search_result' => [
            'label' => 'Search result highlighting input',
            'hasTemplate' => FALSE,
          ],
        ],
      ],
    ], $json);

    $template_data = [
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ];

    // 2. Create ContentTemplate for Full View Mode of Article bundle.
    $template = ContentTemplate::create($template_data);
    $template->save();

    // 3. Test endpoint response, validate Full View Mode `hasTemplate` property of TRUE.
    $json = self::assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/view_modes/node'),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );

    self::assertEquals([
      'node' => [
        'article' => [
          'teaser' => [
            'label' => 'Teaser',
            'hasTemplate' => FALSE,
          ],
          'full' => [
            'label' => 'Full content',
            'hasTemplate' => TRUE,
          ],
          'rss' => [
            'label' => 'RSS',
            'hasTemplate' => FALSE,
          ],
          'search_index' => [
            'label' => 'Search index',
            'hasTemplate' => FALSE,
          ],
          'search_result' => [
            'label' => 'Search result highlighting input',
            'hasTemplate' => FALSE,
          ],
        ],
      ],
    ], $json);

    // 4. Create ContentTemplate for Teaser View Mode.
    $template_data['content_entity_type_view_mode'] = 'teaser';
    $template_data['id'] = 'node.article.teaser';
    $template = ContentTemplate::create($template_data);
    $template->save();

    // 5. Test endpoint response, validate Full and Teaser View Modes have `hasTemplate` property values of TRUE.
    $json = self::assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/view_modes/node'),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );

    self::assertEquals([
      'node' => [
        'article' => [
          'teaser' => [
            'label' => 'Teaser',
            'hasTemplate' => TRUE,
          ],
          'full' => [
            'label' => 'Full content',
            'hasTemplate' => TRUE,
          ],
          'rss' => [
            'label' => 'RSS',
            'hasTemplate' => FALSE,
          ],
          'search_index' => [
            'label' => 'Search index',
            'hasTemplate' => FALSE,
          ],
          'search_result' => [
            'label' => 'Search result highlighting input',
            'hasTemplate' => FALSE,
          ],
        ],
      ],
    ], $json);
  }

}
