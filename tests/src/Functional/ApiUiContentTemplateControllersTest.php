<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests Api Ui Content Template Controllers.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
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

    // Required, single-cardinality datetime field: makes the Date conversion
    // adapter offerable for the article bundle's required text props.
    // @see ::testAdapterSuggestions()
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_date',
      'type' => 'datetime',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_event_date',
      'label' => 'Event date',
      'bundle' => 'article',
      'required' => TRUE,
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
   * Tests suggest prop sources.
   *
   * @see \Drupal\Tests\canvas\Kernel\PropSourceSuggesterTest
   */
  #[DataProvider('providerSuggestPropSources')]
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
    // Adapter suggestions are appended after all direct matches for every
    // prop. Assert that invariant, then strip them: the direct-match
    // expectations below stay readable, and the adapter representations
    // themselves are covered by ::testAdapterSuggestions().
    self::assertIsArray($json);
    foreach ($json as $prop_name => $prop_suggestions) {
      $is_adapter = \array_map(
        fn (array $suggestion): bool => \array_key_exists('adapter', $suggestion),
        $prop_suggestions,
      );
      $first_adapter = \array_search(TRUE, $is_adapter, TRUE);
      if ($first_adapter !== FALSE) {
        self::assertNotContains(FALSE, \array_slice($is_adapter, (int) $first_adapter), "Direct suggestions must precede adapter suggestions for prop `$prop_name`.");
      }
      $json[$prop_name] = \array_values(\array_filter(
        $prop_suggestions,
        fn (array $suggestion): bool => !\array_key_exists('adapter', $suggestion),
      ));
    }
    $this->assertSame($expected, $json);
  }

  /**
   * Tests the adapter suggestions in the prop sources response.
   *
   * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester::buildAdapterSuggestions()
   */
  public function testAdapterSuggestions(): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/node/article/sdc.canvas_test_sdc.heading"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );
    self::assertIsArray($json);

    $adapters_for_prop = fn (string $prop_name): array => \array_values(\array_filter(
      $json[$prop_name],
      fn (array $suggestion): bool => \array_key_exists('adapter', $suggestion),
    ));

    // The `text` prop is a required plain string: all text-producing adapters
    // whose primary input has field candidates match (Date conversion is only
    // here because the bundle has a required datetime field), plus the
    // parametric ones (whose output mirrors designated inputs), in
    // alphabetical label order.
    $text_adapters = $adapters_for_prop('text');
    self::assertSame(
      ['Combine', 'Contains', 'Date conversion', 'Equals', 'Fallback', 'Mapping', 'Prefix and suffix'],
      \array_column($text_adapters, 'label'),
    );

    // The `style` prop is an optional enum-constrained string: only
    // parametric adapters whose primary input has candidates are offered — no
    // direct field suggestions exist, yet the prop is still adaptable (e.g.
    // via Mapping, an option field driving a variant). Fallback is absent:
    // its primary `value` input mirrors the enum shape, which no field
    // matches, so there is nothing to fall back from.
    $style_adapters = $adapters_for_prop('style');
    self::assertSame(
      ['Contains', 'Equals', 'Mapping'],
      \array_column($style_adapters, 'label'),
    );

    // Inspect the full representation of the Equals adapter for `text`.
    $equals = \array_values(\array_filter($text_adapters, fn (array $s): bool => $s['adapter']['id'] === 'equals'))[0];
    self::assertSame(['id', 'label', 'adapter'], \array_keys($equals));
    $inputs = \array_combine(
      \array_column($equals['adapter']['inputs'], 'name'),
      $equals['adapter']['inputs'],
    );
    self::assertSame(['value', 'comparison', 'then', 'else', 'negate'], \array_keys($inputs));
    // Required flags. TRICKY: `text` is a REQUIRED prop, so the conditional's
    // `else` becomes required too — otherwise a non-matching value would
    // produce an empty value for a prop that must not be empty.
    self::assertTrue($inputs['value']['required']);
    self::assertTrue($inputs['comparison']['required']);
    self::assertTrue($inputs['then']['required']);
    self::assertTrue($inputs['else']['required']);
    self::assertFalse($inputs['negate']['required']);
    // For the OPTIONAL `style` prop, `else` remains optional.
    $style_equals = \array_values(\array_filter($style_adapters, fn (array $s): bool => $s['adapter']['id'] === 'equals'))[0];
    $style_else = \array_values(\array_filter($style_equals['adapter']['inputs'], fn (array $i): bool => $i['name'] === 'else'))[0];
    self::assertFalse($style_else['required']);
    // Parametric: `then`/`else` mirror the targeted prop's shape.
    self::assertTrue($inputs['then']['mirrorsOutput']);
    self::assertTrue($inputs['else']['mirrorsOutput']);
    self::assertFalse($inputs['value']['mirrorsOutput']);
    self::assertSame(['type' => 'string'], $inputs['then']['schema']);
    // "Any"-shaped inputs have no schema…
    self::assertNull($inputs['value']['schema']);
    self::assertSame(['type' => 'boolean'], $inputs['negate']['schema']);
    // …but they do get candidates (the union of primitive-shaped fields) and
    // a (string) static template.
    self::assertSame('static:field_item:string', $inputs['value']['static']['sourceType']);
    self::assertSame('static:field_item:boolean', $inputs['negate']['static']['sourceType']);
    $value_candidate_labels = \array_column($inputs['value']['candidates'], 'label');
    self::assertContains('Title', $value_candidate_labels);
    $title_candidate = \array_values(\array_filter($inputs['value']['candidates'], fn (array $c): bool => $c['label'] === 'Title'))[0];
    self::assertSame([
      'sourceType' => PropSource::EntityField->value,
      'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
    ], $title_candidate['source']);
    foreach ($inputs['value']['candidates'] as $candidate) {
      self::assertSame(['id', 'label', 'source'], \array_keys($candidate));
    }

    // The Date conversion adapter's `date` input offers both `format: date`
    // and `format: date-time` fields — but never plain strings.
    $format_date = \array_values(\array_filter($text_adapters, fn (array $s): bool => $s['adapter']['id'] === 'format_date'))[0];
    $date_input = \array_values(\array_filter($format_date['adapter']['inputs'], fn (array $i): bool => $i['name'] === 'date'))[0];
    self::assertContains('Event date', \array_column($date_input['candidates'], 'label'));
    self::assertNotContains('Title', \array_column($date_input['candidates'], 'label'));

    // Where no datetime fields exist — the `user` bundle — Date conversion is
    // not offered at all: there is nothing for it to transform.
    $user_json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri("base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/user/user/sdc.canvas_test_sdc.heading"),
      request_options: [],
      expected_status: Response::HTTP_OK,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: 'UNCACHEABLE (no cacheability)',
    );
    self::assertIsArray($user_json);
    $user_text_adapter_labels = \array_column(
      \array_values(\array_filter($user_json['text'], fn (array $s): bool => \array_key_exists('adapter', $s))),
      'label',
    );
    self::assertNotContains('Date conversion', $user_text_adapter_labels);
    self::assertContains('Equals', $user_text_adapter_labels);
  }

  /**
   * Tests the prop source preview endpoint.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentTemplateControllers::previewPropSource()
   */
  public function testPreviewPropSource(): void {
    $two_days_ago = \time() - 2 * 86400 - 60;
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'article',
      'title' => 'Hello world',
      'created' => $two_days_ago,
    ]);
    $node->save();
    $url = Url::fromUri("base:/canvas/api/v0/ui/content_template/prop-source-preview/node/" . $node->id());

    // An adapted prop source combining an entity field input and a static
    // literal input.
    $json = $this->assertExpectedResponse('POST', $url, [
      RequestOptions::JSON => [
        'source' => [
          'sourceType' => 'adapter:prefix_suffix',
          'adapterInputs' => [
            'value' => [
              'sourceType' => PropSource::EntityField->value,
              'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
            ],
            'prefix' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
              'value' => 'Published: ',
            ],
          ],
        ],
      ],
    ], 200, NULL, NULL, NULL, NULL);
    $this->assertSame(['value' => 'Published: Hello world'], $json);

    // A chained adapter: created (UNIX timestamp) → date string → relative.
    $json = $this->assertExpectedResponse('POST', $url, [
      RequestOptions::JSON => [
        'source' => [
          'sourceType' => 'adapter:format_date',
          'adapterInputs' => [
            'date' => [
              'sourceType' => 'adapter:unix_to_date',
              'adapterInputs' => [
                'unix' => [
                  'sourceType' => PropSource::EntityField->value,
                  'expression' => 'ℹ︎␜entity:node:article␝created␞␟value',
                ],
              ],
            ],
            'format' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
              'value' => 'relative',
            ],
          ],
        ],
      ],
    ], 200, NULL, NULL, NULL, NULL);
    $this->assertSame(['value' => '2 days ago'], $json);

    // An invalid configuration: a structured error, not a 500.
    $json = $this->assertExpectedResponse('POST', $url, [
      RequestOptions::JSON => [
        'source' => [
          'sourceType' => 'adapter:nonexistent',
          'adapterInputs' => [],
        ],
      ],
    ], 422, NULL, NULL, NULL, NULL);
    self::assertIsArray($json);
    self::assertStringContainsString('does not exist', $json['errors'][0]);

    // A missing `source` key. (Bypass the OpenAPI request validator, which
    // would reject this test-only invalid request in dev environments before
    // the controller sees it.)
    $json = $this->assertExpectedResponse('POST', $url, [
      RequestOptions::JSON => ['no_source' => TRUE],
      RequestOptions::HEADERS => ['X-NO-OPENAPI-VALIDATION' => '1'],
    ], 400, NULL, NULL, NULL, NULL);
    $this->assertSame(['errors' => ['The request body must contain a `source` key with a prop source array representation.']], $json);

    // Without the necessary permission: 403. (Assert only status and body:
    // cacheability debug headers on POST error responses vary by core
    // version.)
    $this->drupalLogin($this->limitedPermissionsUser);
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::JSON => ['source' => ['sourceType' => 'adapter:is_set', 'adapterInputs' => []]],
      RequestOptions::HEADERS => ['X-CSRF-Token' => $this->drupalGet('session/token')],
    ]);
    $this->assertSame(403, $response->getStatusCode());
    $json = \json_decode((string) $response->getBody(), TRUE);
    $this->assertSame(['errors' => [\sprintf("The '%s' permission is required.", ContentTemplate::ADMIN_PERMISSION)]], $json);
  }

  public static function providerSuggestPropSources(): \Generator {
    $choice_article_title = [
      'source' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:article␝title␞␟value'],
      'label' => "Title",
    ];
    $choice_article_image = [
      'source' => ['sourceType' => PropSource::EntityField->value, 'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}'],
      'label' => "Silly image 🤡",
    ];
    $choice_article_author_name = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'label' => 'Name',
    ];
    $choice_article_author_picture_alt = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      'label' => 'Alternative text',
    ];
    $choice_article_author_picture_title = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟title',
      ],
      'label' => 'Title',
    ];
    $choice_article_revision_user_name = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
      'label' => 'Name',
    ];
    $choice_article_revision_user_picture_alt = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
        'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      'label' => 'Alternative text',
    ];
    $choice_article_revision_user_picture_title = [
      'source' => [
        'sourceType' => PropSource::EntityField->value,
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
              'sourceType' => PropSource::EntityField->value,
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
                      'sourceType' => PropSource::EntityField->value,
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
                      'sourceType' => PropSource::EntityField->value,
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
              'sourceType' => PropSource::EntityField->value,
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
                  'sourceType' => PropSource::EntityField->value,
                  'expression' => 'ℹ︎␜entity:node:article␝field_screenshots␞␟alt',
                ],
                'label' => 'Alternative text',
              ],
              [
                'id' => '1138e38cc9e6b7dd',
                'source' => [
                  'sourceType' => PropSource::EntityField->value,
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
                      'sourceType' => PropSource::EntityField->value,
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
                  'sourceType' => PropSource::EntityField->value,
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟alt',
                ],
                'label' => "Alternative text",
              ],
              [
                'id' => '1409e675864fd2e6',
                'source' => [
                  'sourceType' => PropSource::EntityField->value,
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
              'sourceType' => PropSource::EntityField->value,
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
                  'sourceType' => PropSource::EntityField->value,
                  'expression' => 'ℹ︎␜entity:node:article␝field_silly_image␞␟alt',
                ],
                'label' => "Alternative text",
              ],
              [
                'id' => '1409e675864fd2e6',
                'source' => [
                  'sourceType' => PropSource::EntityField->value,
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
              'sourceType' => PropSource::EntityField->value,
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
                      'sourceType' => PropSource::EntityField->value,
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
                  'sourceType' => PropSource::EntityField->value,
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
                      'sourceType' => PropSource::EntityField->value,
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
                  'sourceType' => PropSource::EntityField->value,
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
              'sourceType' => PropSource::HostEntityUrl->value,
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
 * Tests suggest prop sources client errors.
 */
  #[TestWith(["a/b/c", 404, "The component c does not exist."])]
  #[TestWith(["a/b/sdc.canvas_test_sdc.image", 404, "The `a` content entity type does not exist."])]
  #[TestWith(["node/b/sdc.canvas_test_sdc.image", 404, "The `node` content entity type does not have a `b` bundle."])]
  #[TestWith(["node/article/block.user_login_block", 400, "Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported."])]
  public function testSuggestPropSourcesClientErrors(string $trail, int $expected_status_code, string $expected_error_message): void {
    $json = $this->assertExpectedResponse(
      method: 'GET',
      url: Url::fromUri('base:/canvas/api/v0/ui/content_template/suggestions/prop-sources/' . $trail),
      request_options: [],
      expected_status: $expected_status_code,
      expected_cache_contexts: NULL,
      expected_cache_tags: NULL,
      expected_page_cache: 'UNCACHEABLE (request policy)',
      expected_dynamic_page_cache: "UNCACHEABLE (no cacheability)",
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
      expected_dynamic_page_cache: 'UNCACHEABLE (403)',
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

  /**
   * The `search` module's node view modes, removed as of Drupal 11.4.
   *
   * Drupal 11.4 moved node-specific search functionality out of the Search
   * module, including the `search_index` and `search_result` view modes. They
   * were moved into a new `search_node` submodule, which this test does
   * install.
   *
   * @return array<string, array{label: string, hasTemplate: bool}>
   *
   * @see https://www.drupal.org/node/3587564
   * @see https://www.drupal.org/node/3590298
   */
  private static function expectSearchViewModesOnDrupal113(): array {
    if (version_compare(\Drupal::VERSION, '11.4', '>=')) {
      return [];
    }
    return [
      'search_index' => [
        'label' => 'Search index',
        'hasTemplate' => FALSE,
      ],
      'search_result' => [
        'label' => 'Search result highlighting input',
        'hasTemplate' => FALSE,
      ],
    ];
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
          ...self::expectSearchViewModesOnDrupal113(),
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
          ...self::expectSearchViewModesOnDrupal113(),
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
          ...self::expectSearchViewModesOnDrupal113(),
        ],
      ],
    ], $json);
  }

}
