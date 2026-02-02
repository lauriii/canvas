<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Test JS Components can evolve over time.
 *
 * @group canvas
 */
final class JsComponentEvolutionTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use CiModulePathTrait;
  use CrawlerTrait;
  use ComponentTreeItemListInstantiatorTrait;
  use ClientServerConversionTrait;
  use ConstraintViolationsTestTrait;
  use UserCreationTrait;
  use RequestTrait;

  private const string JAVASCRIPT_COMPONENT_ID = 'canvas_test_code_components_with_slots';
  private const string COMPONENT_ID = 'js.' . self::JAVASCRIPT_COMPONENT_ID;
  private const string COMPONENT_INSTANCE_UUID = '1191fb41-5fb7-4ed3-955d-03df4fde199d';
  private const string CHILD_COMPONENT_INSTANCE_ID = 'e8550590-f11b-431e-8932-340d2c00c103';

  protected UuidInterface $uuid;
  protected string $originalVersion;
  protected string $childType;
  protected array $expectedOriginalClientModel = [];
  protected array $originalClientModel = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'media',
    'path',
    'file',
    'image',
    'link',
    'options',
    'text',
    'system',
    'block',
    'datetime',
    'user',
    'filter',
    'ckeditor5',
    'editor',
    'canvas_test_code_components',
  ];

  protected function reloadJavascriptComponent(): JavascriptComponent {
    /** @var \Drupal\canvas\Entity\JavaScriptComponent */
    return \Drupal::entityTypeManager()->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)->loadUnchanged(self::JAVASCRIPT_COMPONENT_ID);
  }

  protected function reloadComponent(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->loadUnchanged(self::COMPONENT_ID);
  }

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    \Drupal::service(ThemeInstallerInterface::class)->install(['canvas_stark']);
    $this->uuid = \Drupal::service(UuidInterface::class);
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    Page::create([
      'title' => 'Interior Live Oak',
    ])->save();
    $this->installSchema('user', 'users_data');
    $this->installConfig('canvas');
    $this->installConfig('canvas_test_code_components');
    // Set up a test user "bob"
    $this->setUpCurrentUser(['name' => 'bob', 'uid' => 2], [JavaScriptComponent::ADMIN_PERMISSION, Page::EDIT_PERMISSION]);
    $component = $this->reloadComponent();
    $prop_field_definitions = $component->getSettings()['prop_field_definitions'];
    self::assertEquals([
      'name' => 'string',
    ], \array_map(static fn (array $field) => $field['field_type'], $prop_field_definitions));
    self::assertTrue($prop_field_definitions['name']['required']);

    // Create an item for the component in its current form.
    $items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $this->originalVersion = $component->getActiveVersion();
    self::assertSame([$this->originalVersion], $component->getVersions());

    $childComponent = Component::load('js.canvas_test_code_components_with_no_props');
    self::assertInstanceOf(ComponentInterface::class, $childComponent);
    $this->childType = \sprintf('%s@%s', $childComponent->id(), $childComponent->getActiveVersion());

    $items->setValue([
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => $component->id(),
        'inputs' => [
          'name' => 'D. Boon',
        ],
      ],
      [
        'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
        'component_id' => $childComponent->id(),
        'inputs' => [],
        'parent_uuid' => self::COMPONENT_INSTANCE_UUID,
        'slot' => 'description',
      ],
    ]);
    self::assertCount(0, $items->validate());

    // Creating a component of this type should set the `component_version`
    // field property and column to the active version.
    self::assertSame($this->originalVersion, $items->first()?->getComponentVersion());
    self::assertSame($this->originalVersion, $items->getValue()[0]['component_version']);

    // Converting to a client-side model should expand the plain inputs into
    // structured values.
    // @todo Simplify the client-side model in https://www.drupal.org/i/3528043
    $this->originalClientModel = $items->getClientSideRepresentation();

    $this->expectedOriginalClientModel = [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $this->originalVersion),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'resolved' => [
            'name' => 'D. Boon',
          ],
        ],
      ],
    ];
    self::assertEquals($this->expectedOriginalClientModel, $this->originalClientModel);
  }

  protected function assertNewVersion(array $expectedFieldTypes, array $inputs, callable $expectedClientModelFunction, bool $withChild = TRUE): ComponentTreeItemList {
    $component = $this->reloadComponent();
    $new_version = $component->getActiveVersion();
    self::assertNotEquals($this->originalVersion, $new_version);
    self::assertCount(0, \array_diff([$new_version, $this->originalVersion], $component->getVersions()));
    self::assertEquals($expectedFieldTypes, \array_map(static fn(array $field) => $field['field_type'], $component->getSettings()['prop_field_definitions']));

    $new_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $values = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => $component->id(),
        'inputs' => $inputs,
      ],
    ];
    if ($withChild) {
      $values[] = [
        'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
        'component_id' => 'js.canvas_test_code_components_with_no_props',
        'inputs' => [],
        'parent_uuid' => self::COMPONENT_INSTANCE_UUID,
        'slot' => 'description',
      ];
    }
    $new_items->setValue($values);
    $violations = $new_items->validate();
    self::assertCount(0, $violations, \implode(', ', \array_map(static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(), \iterator_to_array($violations))));

    // Creating a component of this type should set the `component_version`
    // field property and column to the active version.
    self::assertSame($new_version, $new_items->first()?->getComponentVersion());
    self::assertSame($new_version, $new_items->getValue()[0]['component_version']);

    $new_client_model = $new_items->getClientSideRepresentation();

    self::assertEquals($expectedClientModelFunction($new_version), $new_client_model);

    // Converting the old client model should still retain the reference to the
    // old version.
    $component_tree_item_list_values = self::convertClientToServer($this->originalClientModel['layout'], $this->originalClientModel['model']);
    \assert(\array_key_exists('component_version', $component_tree_item_list_values[0]));
    self::assertSame($this->originalVersion, $component_tree_item_list_values[0]['component_version']);
    // Create a new item list from this.
    $original_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $original_items->setValue($component_tree_item_list_values);
    self::assertCount(0, $original_items->validate());
    // Should still equal the original model, even though the field type is now
    // different data type prop for new component instances: existing
    // component instances remain unchanged.
    self::assertEquals($this->expectedOriginalClientModel, $original_items->getClientSideRepresentation());

    // Test can still edit the old component in a form.
    $this->request(Request::create(\sprintf('/canvas/api/v0/form/component-instance/%s/1', Page::ENTITY_TYPE_ID), 'PATCH', [
      'form_canvas_tree' => \json_encode([
        'nodeType' => 'component',
        'slots' => [],
        'type' => \sprintf('%s@%s', self::COMPONENT_ID, $this->originalVersion),
        'uuid' => self::COMPONENT_INSTANCE_UUID,
      ], JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode($this->originalClientModel['model'][self::COMPONENT_INSTANCE_UUID], JSON_THROW_ON_ERROR),
      'form_canvas_selected' => self::COMPONENT_INSTANCE_UUID,
    ]));

    return $new_items;
  }

  protected function patchComponent(array $data, array $expectedViolations = []): void {
    // Don't update these.
    unset($data['sourceCodeJs']);
    unset($data['compiledJs']);
    $headers = [
      'X-CSRF-Token' => \Drupal::service(CsrfTokenGenerator::class)->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
      'Content-Type' => 'application/json',
    ];
    $request = Request::create(
      Url::fromUri('base:/canvas/api/v0/config/js_component/' . self::JAVASCRIPT_COMPONENT_ID)->toString(),
      'PATCH',
      content: \json_encode($data, \JSON_THROW_ON_ERROR),
    );
    $request->headers->add($headers);
    try {
      $response = $this->request($request);
      if (\count($expectedViolations) === 0) {
        self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
        return;
      }
      $this->fail('Was expecting an error.');
    }
    catch (ConstraintViolationException $e) {
      self::assertEquals(
        $expectedViolations,
        \array_reduce(
          \iterator_to_array($e->getConstraintViolationList()),
          static fn(array $carry, ConstraintViolationInterface $violation): array => $carry + [$violation->getPropertyPath() => (string) $violation->getMessage()],
          []
        )
          );
    }
  }

  protected function addOrUpdateAgeProp(bool $usingHttpRequest = FALSE, bool $required = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $prop = [
      'title' => 'Age',
      'type' => 'integer',
      'examples' => [],
    ];
    if (!$required) {
      $props['examples'][] = 27;
    }
    $requiredProps = \array_diff($js_component->getRequiredProps(), ['age']);
    if ($required) {
      $requiredProps[] = 'age';
    }
    if (!$usingHttpRequest) {
      $props = $js_component->getProps();
      $props['age'] = $prop;
      $js_component->set('required', $requiredProps);
      $js_component->setProps($props);
      if ($required) {
        // Ensure missing an example for a required prop triggers a validation
        // error.
        $violations = $js_component->getTypedData()->validate();
        self::assertCount(1, $violations);
        self::assertEquals('Prop "age" is required, but does not have example value', (string) $violations[0]?->getMessage());
        // Fix the missing example.
        $props['age']['examples'][] = 27;
        $js_component->setProps($props);
        self::assertCount(0, $js_component->getTypedData()->validate());
      }
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['props']['age'] = $prop;
    $data['required'] = $requiredProps;
    if ($required) {
      // Ensure missing an example for a required prop triggers a validation
      // error.
      $this->patchComponent($data, ['' => 'Prop "age" is required, but does not have example value']);
      // Fix the missing example.
      $data['props']['age']['examples'][] = 27;
    }
    $this->patchComponent($data);
  }

  protected function assertOptionalPropNewVersion(): void {
    $expectedFieldTypes = [
      'name' => 'string',
      'age' => 'integer',
    ];
    $expectedClientModelFunction = fn(array $inputs) => fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => \array_intersect_key([
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'age' => [
              'sourceType' => 'static:field_item:integer',
              'expression' => 'ℹ︎integer␟value',
            ],
          ], $inputs),
          'resolved' => $inputs,
        ],
      ],
    ];
    $inputs = [
      // Omit new optional prop.
      'name' => 'Mike Watt',
    ];
    $this->assertNewVersion($expectedFieldTypes, $inputs, $expectedClientModelFunction($inputs));
    $inputs = [
      'name' => 'Mike Watt',
      // Populate the new prop.
      'age' => 27,
    ];
    $this->assertNewVersion($expectedFieldTypes, $inputs, $expectedClientModelFunction($inputs));
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanAddOptionalProp(bool $usingHttpApi = FALSE): void {
    $this->addOrUpdateAgeProp($usingHttpApi);
    $this->assertOptionalPropNewVersion();
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanAddRequiredProp(bool $usingHttpApi = FALSE): void {
    $this->addOrUpdateAgeProp($usingHttpApi, TRUE);
    $this->assertRequiredPropNewVersion();
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanRemoveRequiredPropThenAddAnotherRequiredProp(bool $usingHttpApi = FALSE): void {
    $this->removeNamePropAndAddAgeProp($usingHttpApi, TRUE);

    $inputs = [
      // Populate the new prop.
      'age' => 27,
    ];
    // We removed a required prop, so we can only test with the latest version.
    $this->assertNewVersion([
      'age' => 'integer',
    ], $inputs, fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'age' => [
              'sourceType' => 'static:field_item:integer',
              'expression' => 'ℹ︎integer␟value',
            ],
          ],
          'resolved' => $inputs,
        ],
      ],
    ]);
  }

  protected function removeNamePropAndAddAgeProp(bool $usingHttpRequest = FALSE, bool $required = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $props = $js_component->getProps();
    \assert(\is_array($props));
    self::assertArrayHasKey('name', $props);
    unset($props['name']);
    $props['age'] = [
      'title' => 'Age',
      'type' => 'integer',
      'examples' => [],
    ];

    $requiredProps = \array_diff($js_component->getRequiredProps(), ['name']);
    if ($required) {
      $props['age']['examples'][] = 27;
      $requiredProps[] = 'age';
    }
    if (!$usingHttpRequest) {
      $js_component->set('required', $requiredProps);
      $js_component->setProps($props);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['props'] = $props;
    $data['required'] = $requiredProps;
    $this->patchComponent($data);
  }

  protected function assertRequiredPropNewVersion(): void {
    $inputs = [
      'name' => 'Mike Watt',
      // Populate the new prop.
      'age' => 27,
    ];
    $this->assertNewVersion([
      'name' => 'string',
      'age' => 'integer',
    ], $inputs, fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'age' => [
              'sourceType' => 'static:field_item:integer',
              'expression' => 'ℹ︎integer␟value',
            ],
          ],
          'resolved' => $inputs,
        ],
      ],
    ]);
  }

  protected function addSlot(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $slot = [
      'title' => 'Introduction',
    ];
    if (!$usingHttpRequest) {
      $slots = $js_component->get('slots');
      $slots['intro'] = $slot;
      $js_component->set('slots', $slots);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['slots']['intro'] = $slot;
    $this->patchComponent($data);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanAddNewSlot(bool $usingHttpApi = FALSE): void {
    $this->addSlot($usingHttpApi);
    $inputs = [
      'name' => 'mike_watt',
    ];
    $items = $this->assertNewVersion([
      'name' => 'string',
    ],
      $inputs,
      fn (string $version) => [
        'layout' => [
          [
            'uuid' => self::COMPONENT_INSTANCE_UUID,
            'nodeType' => 'component',
            'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
            'slots' => [
              [
                'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
                'name' => 'description',
                'nodeType' => 'slot',
                'components' => [
                  [
                    'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                    'nodeType' => 'component',
                    'type' => $this->childType,
                    'slots' => [],
                    'name' => NULL,
                  ],
                ],
              ],
              [
                'id' => \sprintf('%s/intro', self::COMPONENT_INSTANCE_UUID),
                'name' => 'intro',
                'nodeType' => 'slot',
                'components' => [],
              ],
            ],
            'name' => NULL,
          ],
        ],
        'model' => [
          self::COMPONENT_INSTANCE_UUID => [
            'source' => [
              'name' => [
                'sourceType' => 'static:field_item:string',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
            'resolved' => $inputs,
          ],
        ],
      ]);
    // Validate that the slot can be populated.
    $new_uuid = $this->uuid->generate();
    $component = Component::load('js.canvas_test_code_components_with_no_props');
    self::assertInstanceOf(ComponentInterface::class, $component);
    $items->appendItem(
      [
        'uuid' => $new_uuid,
        'component_id' => $component->id(),
        'inputs' => [],
        'parent_uuid' => self::COMPONENT_INSTANCE_UUID,
        'slot' => 'intro',
      ],
    );
    self::assertCount(0, $items->validate());
    $first_item = $items->get(0);
    \assert($first_item instanceof ComponentTreeItem);
    self::assertEquals([
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $first_item->getComponentVersion()),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ], [
              'id' => \sprintf('%s/intro', self::COMPONENT_INSTANCE_UUID),
              'name' => 'intro',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => $new_uuid,
                  'nodeType' => 'component',
                  'type' => \sprintf('%s@%s', $component->id(), $component->getActiveVersion()),
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'resolved' => $inputs,
        ],
      ],
    ], $items->getClientSideRepresentation());
  }

  protected function makeAgePropRequired(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    self::assertNotContains('age', $js_component->getRequiredProps());
    // We can make use of the same code for adding the prop. Because we're
    // updating the $props['age'] property using the 'age' key, the code is the
    // same now that we've confirmed the prop isn't already required.
    $this->addOrUpdateAgeProp($usingHttpRequest, TRUE);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanMakeAnOptionalPropRequired(bool $usingHttpApi = FALSE): void {
    $this->addOrUpdateAgeProp($usingHttpApi);
    $this->makeAgePropRequired($usingHttpApi);
    $this->assertRequiredPropNewVersion();
  }

  protected function makeAgePropOptional(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    self::assertContains('age', $js_component->getRequiredProps());
    // We can make use of the same code for adding the prop. Because we're
    // updating the $props['age'] property using the 'age' key, the code is the
    // same now that we've confirmed the prop is already required.
    $this->addOrUpdateAgeProp($usingHttpRequest);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanMakeARequiredPropOptional(bool $usingHttpApi = FALSE): void {
    $this->addOrUpdateAgeProp($usingHttpApi, TRUE);
    $this->makeAgePropOptional($usingHttpApi);
    $this->assertOptionalPropNewVersion();
  }

  protected function removeNameProp(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $props = $js_component->getProps();
    \assert(\is_array($props));
    self::assertArrayHasKey('name', $props);
    unset($props['name']);
    $requiredProps = \array_diff($js_component->getRequiredProps(), ['name']);
    if (!$usingHttpRequest) {
      $js_component->set('required', $requiredProps);
      $js_component->setProps($props);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['props'] = $props;
    $data['required'] = $requiredProps;
    $this->patchComponent($data);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanRemoveProp(bool $usingHttpApi = FALSE): void {
    $this->removeNameProp($usingHttpApi);
    $expectedClientModelFunction = fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [],
    ];
    $this->assertNewVersion([], [], $expectedClientModelFunction);
  }

  protected function modifyExamples(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $props = $js_component->getProps();
    \assert(\is_array($props));
    self::assertArrayHasKey('name', $props);
    $props['name']['examples'] = ['George Hurley'];
    $slots = $js_component->get('slots');
    self::assertArrayHasKey('description', $slots);
    $slots['description']['examples'] = ['Double nickels on the dime'];
    if (!$usingHttpRequest) {
      $js_component->setProps($props);
      $js_component->set('slots', $slots);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['props'] = $props;
    $data['slots'] = $slots;
    $this->patchComponent($data);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanModifyDefaultValuesAndExamples(bool $usingHttpApi = FALSE): void {
    $this->modifyExamples($usingHttpApi);
    $inputs = [
      'name' => 'Mike Watt',
    ];
    $expectedClientModelFunction = fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'resolved' => $inputs,
        ],
      ],
    ];
    $this->assertNewVersion([
      'name' => 'string',
    ], $inputs, $expectedClientModelFunction);
  }

  protected function removeDescriptionSlot(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $slots = $js_component->get('slots');
    self::assertArrayHasKey('description', $slots);
    unset($slots['description']);
    if (!$usingHttpRequest) {
      $js_component->set('slots', $slots);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['slots'] = $slots;
    $this->patchComponent($data);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanRemoveASlot(bool $usingHttpApi = FALSE): void {
    $this->removeDescriptionSlot($usingHttpApi);
    $inputs = ['name' => 'D. Boon'];
    $expectedClientModelFunction = fn(string $version) => [
      'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [],
          'name' => NULL,
        ],
      ],
      'model' => [
        self::COMPONENT_INSTANCE_UUID => [
          'source' => [
            'name' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'resolved' => $inputs,
        ],
      ],
    ];
    $new_items = $this->assertNewVersion([
      'name' => 'string',
    ], $inputs, $expectedClientModelFunction, FALSE);

    // New version has no slots; adding a child should be rejected.
    $new_items->appendItem([
      'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
      'component_id' => 'js.canvas_test_code_components_with_no_props',
      'inputs' => [],
      'parent_uuid' => self::COMPONENT_INSTANCE_UUID,
      'slot' => 'description',
    ]);
    $violations = $new_items->validate();
    self::assertSame([
      '1.parent_uuid' => 'Invalid component subtree. A component subtree must only exist for components with >=1 slot, but the component <em class="placeholder">js.canvas_test_code_components_with_slots</em> has no slots, yet a subtree exists for the instance with UUID <em class="placeholder">1191fb41-5fb7-4ed3-955d-03df4fde199d</em>.',
    ], self::violationsToArray($violations));

    // Old instances keep their historical version and remain valid.
    $component_tree_item_list_values = self::convertClientToServer($this->originalClientModel['layout'], $this->originalClientModel['model']);
    $original_items = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $original_items->setValue($component_tree_item_list_values);
    self::assertCount(0, $original_items->validate(), 'Old component instances referencing historical versions with the slot should still validate');

    // Verify the child is still in the correct slot.
    $values = $original_items->getValue();
    self::assertCount(2, $values, 'Both parent and child should be present');
    self::assertSame('description', $values[1]['slot'], 'Child should still be in description slot');
    self::assertSame(self::COMPONENT_INSTANCE_UUID, $values[1]['parent_uuid'], 'Child should still reference parent');
  }

  protected function reAddDescriptionSlot(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $slots = $js_component->get('slots');
    $slots['description'] = [
      'title' => 'Description',
      'examples' => ['<p>Example description</p>'],
    ];
    if (!$usingHttpRequest) {
      $js_component->set('slots', $slots);
      self::assertCount(0, $js_component->getTypedData()->validate());
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['slots'] = $slots;
    $this->patchComponent($data);
  }

  protected function modifyPropType(bool $usingHttpRequest = FALSE): void {
    $js_component = $this->reloadJavascriptComponent();
    $options = [
      'd_boon' => 'D. Boon',
      'mike_watt' => 'Mike Watt',
      'george_hurley' => 'George Hurley',
    ];
    if (!$usingHttpRequest) {
      $props = $js_component->getProps();
      $props['name']['enum'] = \array_keys($options);
      $props['name']['meta:enum'] = $options;
      $props['name']['examples'] = ['d_boon'];
      $js_component->setProps($props);
      $js_component->save();
      return;
    }
    $data = $js_component->normalizeForClientSide()->values;
    $data['props']['name']['enum'] = \array_keys($options);
    $data['props']['name']['meta:enum'] = $options;
    $data['props']['name']['examples'] = ['d_boon'];
    $this->patchComponent($data);
  }

  #[DataProvider('providerTrueFalse')]
  public function testCodeComponentCanChangeThePropType(bool $usingHttpApi = FALSE): void {
    $this->markTestSkipped('To be fixed in https://www.drupal.org/project/canvas/issues/3557271');
    $this->modifyPropType($usingHttpApi);
    $inputs = [
      'name' => 'mike_watt',
    ];
    $this->assertNewVersion([
      'name' => 'list_string',
    ],
      $inputs,
      fn (string $version) => [
        'layout' => [
        [
          'uuid' => self::COMPONENT_INSTANCE_UUID,
          'nodeType' => 'component',
          'type' => \sprintf('%s@%s', self::COMPONENT_ID, $version),
          'slots' => [
            [
              'id' => \sprintf('%s/description', self::COMPONENT_INSTANCE_UUID),
              'name' => 'description',
              'nodeType' => 'slot',
              'components' => [
                [
                  'uuid' => self::CHILD_COMPONENT_INSTANCE_ID,
                  'nodeType' => 'component',
                  'type' => $this->childType,
                  'slots' => [],
                  'name' => NULL,
                ],
              ],
            ],
          ],
          'name' => NULL,
        ],
        ],
        'model' => [
          self::COMPONENT_INSTANCE_UUID => [
            'source' => [
              'name' => [
                'sourceType' => 'static:field_item:list_string',
                'expression' => 'ℹ︎list_string␟value',
                'sourceTypeSettings' => [
                  'storage' => [
                    'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
                  ],
                ],
              ],
            ],
            'resolved' => $inputs,
          ],
        ],
      ]);
  }

  public static function providerTrueFalse(): iterable {
    yield 'Using Drupal APIS' => [FALSE];
    yield 'Using HTTP APIS' => [TRUE];
  }

}
