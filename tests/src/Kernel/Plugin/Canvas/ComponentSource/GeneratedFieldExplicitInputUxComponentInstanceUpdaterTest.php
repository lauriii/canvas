<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdater;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\PropShape\PersistentPropShapeRepository;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @coversDefaultClass \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdater
 * @group canvas
 * @group canvas_component_sources
 * @group canvas_data_model
 */
class GeneratedFieldExplicitInputUxComponentInstanceUpdaterTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use ComponentTreeItemListInstantiatorTrait;
  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
  ];

  private JavaScriptComponent $jsComponent;

  protected const string COMPONENT_INSTANCE_UUID = '2c6e91ae-23ac-433d-9bb8-687144464b34';
  protected const string ORIGINAL_VERSION_HASH = '00706dc2aa3d68d1';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');
    $this->installConfig(['filter']);

    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStoragePropShapeAlter()
    $this->createMediaType('image', ['id' => 'baby_photos']);

    $props = [
      'required_text' => [
        'type' => 'string',
        'title' => 'Required Text',
        'examples' => ['Press', 'Submit now'],
      ],
      'optional_text' => [
        'type' => 'string',
        'title' => 'Optional Text',
        'examples' => ['Press', 'Submit now'],
      ],
      'background' => [
        'type' => 'object',
        '$ref' => 'json-schema-definitions://canvas.module/image',
        'title' => 'Background image',
        'examples' => [
          [
            'src' => 'https://placehold.co/1200x900@2x.png',
            'width' => 1200,
            'height' => 900,
            'alt' => 'Example image placeholder',
          ],
        ],
      ],
    ];
    $this->jsComponent = JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => $props,
      'required' => ['required_text'],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
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
    $this->assertSame(SAVED_NEW, $this->jsComponent->save());
  }

  /**
   * @param string $new_latest_version
   *   The version hash after the update.
   * @param ?callable $setup_callback
   *   The optional callback function for setting up the scenario, often by
   *   editing the javascript component, so a new version is generated.
   * @param \Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult $update_result
   *   Enum defining the result of the update attempt.
   * @return void
   */
  #[DataProvider('providerUpdate')]
  public function testUpdate(string $new_latest_version, ?callable $setup_callback, ComponentInstanceUpdateAttemptResult $update_result): void {
    $sut = new GeneratedFieldExplicitInputUxComponentInstanceUpdater();
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, []);
    }
    $component_instance_value = [
      'uuid' => self::COMPONENT_INSTANCE_UUID,
      'component_id' => 'js.test',
      'component_version' => self::ORIGINAL_VERSION_HASH,
      'parent_uuid' => NULL,
      'inputs' => [
        'required_text' => 'Canvas is large and in charge!',
        'optional_text' => 'shouting',
      ],
    ];
    $component_instance = self::generateComponentInstance($component_instance_value);
    $this->assertSame($update_result, $sut->update($component_instance));
    $this->assertSame($new_latest_version, $component_instance->getComponentVersion());

    // Ensure we have the expected versions, as a validation of the test itself.
    $component = Component::load('js.test');
    self::assertNotNull($component);
    $this->assertCount($setup_callback === NULL ? 1 : 2, $component->getVersions());
  }

  /**
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdater::canUpdate
   *   where we document every scenario.
   */
  public static function providerUpdate(): \Generator {
    yield "Component instance already on latest version" => [
      self::ORIGINAL_VERSION_HASH,
      NULL,
      ComponentInstanceUpdateAttemptResult::NotNeeded,
    ];
    // If a new optional prop was added, the component instance can be updated.
    yield "Component added a new optional prop" => [
      'b2d91be1e5b7cc1b',
      [self::class, 'addOptionalProp'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If an optional prop was removed, the component instance cannot be updated.
    yield "Component removed an optional prop" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'removeOptionalProp'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If a new required prop was added, the component instance cannot be updated.
    // @todo It should be updated in https://www.drupal.org/i/3568602.
    yield "Component added a new required prop" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'addRequiredProp'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If a required prop was removed, the component instance can be updated.
    yield "Component removed a required prop" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'removeRequiredProp'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If a required prop became optional, the component instance can be updated.
    yield "Component required prop became optional" => [
      '0783eed5599a2bcb',
      [self::class, 'makeRequiredPropOptional'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If an optional prop became required, the component instance cannot be updated.
    // @todo It should be updated in https://www.drupal.org/i/3568602.
    yield "Component optional prop became required" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'makeOptionalPropRequired'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If examples for a prop changed, the component instance can be updated.
    yield "Component prop examples changed" => [
      '1f82522117964177',
      [self::class, 'changeExamplesFromProp'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If the type for a prop changed, the component instance cannot be updated.
    yield "Component prop type changed" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'changePropType'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If the widget for a prop changed, the component instance can be updated.
    yield "Component prop shape changed its widget" => [
      '92adccfd864b2131',
      [self::class, 'changePropShapeWidget'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If the expression for a prop changed but the field data is compatible,
    // the component instance can be updated.
    yield "Component prop shape changed its expression" => [
      'd3202d1ddae0284c',
      [self::class, 'changeExpression'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // TRICKY: deleting image media types is prevented by config dependencies,
    // so testing that is not needed: it is a situation that Drupal already
    // prevents from happening.
    // @see \Drupal\canvas\Entity\Component::onDependencyRemoval()
    yield "Component prop shape changed its expression AND instance settings for an entity_reference field type" => [
      'fb420688327035c2',
      [self::class, 'createNewImageMediaType'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If a new slot was added, the component instance can be updated.
    yield "Component added a new slot" => [
      'cfbc878f8ec00bb1',
      [self::class, 'addSlot'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
    // If a slot was removed, the component instance cannot be updated.
    yield "Component removed an existing slot" => [
      self::ORIGINAL_VERSION_HASH,
      [self::class, 'removeSlot'],
      ComponentInstanceUpdateAttemptResult::NotAllowed,
    ];
    // If examples for a prop changed, the component instance can be updated.
    yield "Component slot examples changed" => [
      '92034a6805921dd0',
      [self::class, 'changeExamplesFromSlot'],
      ComponentInstanceUpdateAttemptResult::Latest,
    ];
  }

  /**
   * @param string $new_latest_version
   *   The version hash after the update.
   * @param ?callable $setup_callback
   *   The optional callback function for setting up the scenario, often by
   *   editing the javascript component, so a new version is generated.
   * @param \Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult $update_result
   *   Enum defining the result of the update attempt.
   * @return void
   */
  #[DataProvider('providerUpdate')]
  public function testCanUpdate(string $new_latest_version, ?callable $setup_callback, ComponentInstanceUpdateAttemptResult $update_result): void {
    $sut = new GeneratedFieldExplicitInputUxComponentInstanceUpdater();
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, []);
    }

    $component_instance_value = [
      'uuid' => self::COMPONENT_INSTANCE_UUID,
      'component_id' => 'js.test',
      'component_version' => self::ORIGINAL_VERSION_HASH,
      'parent_uuid' => NULL,
      'inputs' => [
        'required_text' => 'Canvas is large and in charge!',
        'optional_text' => 'shouting',
      ],
    ];
    $component_instance = self::generateComponentInstance($component_instance_value);
    $this->assertSame($update_result === ComponentInstanceUpdateAttemptResult::Latest, $sut->canUpdate($component_instance));
    // Ensure we have the expected versions, as a validation of the test itself.
    $component = Component::load('js.test');
    self::assertNotNull($component);
    $this->assertCount($setup_callback === NULL ? 1 : 2, $component->getVersions());
  }

  private static function generateComponentInstance(array $component_instance_value): ComponentTreeItem {
    $component_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $component_tree->setValue($component_instance_value);
    self::assertCount(0, $component_tree->validate(), (string) $component_tree->validate());
    $component_instance = $component_tree->first();
    \assert($component_instance instanceof ComponentTreeItem);
    return $component_instance;
  }

  /**
   * @param array $component_instance_value
   *   The component instance value to test.
   * @param ?callable $setup_callback
   *   The optional callback function for setting up the scenario, often by
   *   editing the javascript component, so a new version is generated.
   * @param bool $expected
   *   TRUE if an update is needed, FALSE otherwise.
   * @return void
   */
  #[DataProvider('providerUpdateNeeded')]
  public function testIsUpdateNeeded(array $component_instance_value, ?callable $setup_callback, bool $expected): void {
    $sut = new GeneratedFieldExplicitInputUxComponentInstanceUpdater();
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, []);
    }

    $component_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $component_tree->setValue($component_instance_value);
    // We explicitly don't validate the tree when the missing component exists,
    // as we want to allow that for properly testing we are handling it.
    if ($component_instance_value['component_id'] !== 'sdc.canvas_test_sdc.missing-component') {
      self::assertCount(0, $component_tree->validate(), (string) $component_tree->validate());
    }
    $component_instance = $component_tree->first();
    \assert($component_instance instanceof ComponentTreeItem);

    $result = $sut->isUpdateNeeded($component_instance);
    $this->assertSame($expected, $result);
  }

  public static function providerUpdateNeeded(): \Generator {
    $missing_component_instance = [
      'uuid' => self::COMPONENT_INSTANCE_UUID,
      'component_id' => 'sdc.canvas_test_sdc.missing-component',
      'component_version' => self::ORIGINAL_VERSION_HASH,
      'parent_uuid' => NULL,
      'inputs' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'Hello world',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    $test_component_instance = [
      'uuid' => self::COMPONENT_INSTANCE_UUID,
      'component_id' => 'js.test',
      'component_version' => self::ORIGINAL_VERSION_HASH,
      'parent_uuid' => NULL,
      'inputs' => [
        'required_text' => 'Canvas is large and in charge!',
      ],
    ];

    yield "Component doesn't exist" => [
      $missing_component_instance,
      NULL,
      FALSE,
    ];
    yield "Component already on latest version" => [
      $test_component_instance,
      NULL,
      FALSE,
    ];
    yield "Component not on latest version" => [
      $test_component_instance,
      [self::class, 'addOptionalProp'],
      TRUE,
    ];
  }

  protected function addOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert(!is_null($props));
    $props['voice'] = [
      'type' => 'string',
      'title' => 'Voice',
      'examples' => ['polite'],
    ];
    $this->jsComponent->setProps($props)
      ->save();
  }

  protected function removeOptionalProp(): void {
    $props = $this->jsComponent->getProps();
    \assert(!is_null($props));
    unset($props['optional_text']);
    $this->jsComponent->setProps($props)
      ->save();
  }

  protected function removeRequiredProp(): void {
    $props = $this->jsComponent->getProps();
    \assert(!is_null($props));
    unset($props['required_text']);
    $this->jsComponent->setProps($props)
      ->save();
  }

  protected function makeRequiredPropOptional(): void {
    $required_props = $this->jsComponent->getRequiredProps();
    $this->jsComponent->set('required', \array_diff($required_props, ['required_text']))
      ->save();
  }

  protected function makeOptionalPropRequired(): void {
    $required_props = $this->jsComponent->getRequiredProps();
    $required_props[] = 'optional_text';
    $this->jsComponent->set('required', $required_props)
      ->save();
  }

  protected function addRequiredProp(): void {
    $props = $this->jsComponent->getProps();
    $required_props = $this->jsComponent->getRequiredProps();
    \assert(!is_null($props));
    $props['voice'] = [
      'type' => 'string',
      'title' => 'Voice',
      'examples' => ['polite'],
    ];
    $required_props[] = 'voice';
    $this->jsComponent
      ->setProps($props)
      ->set('required', $required_props)
      ->save();
  }

  protected function changePropType(): void {
    $props = $this->jsComponent->getProps();
    \assert(!is_null($props));
    $props['optional_text']['enum'] = [
      'polite',
      'shouting',
      'toddler on a sugar high',
    ];
    $props['optional_text']['examples'] = [
      'shouting',
    ];
    $this->jsComponent->setProps($props)
      ->save();
  }

  protected function changeExamplesFromProp(): void {
    $props = $this->jsComponent->getProps();
    \assert(!is_null($props));
    $props['required_text']['examples'] = [
      'A brand new example for a prop',
    ];
    $this->jsComponent->setProps($props)
      ->save();
  }

  protected function changePropShapeWidget(): void {
    // We don't have any good example of a different widget without changing anything
    // else. So let's just edit the Component config itself.
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId($this->jsComponent->id()));
    \assert($component instanceof Component);
    self::assertCount(1, $component->getVersions());
    $settings = $component->getSettings();
    // This widget would actually use a different expression (and it's not even
    // valid for the `string` data type!), so it's an unrealistic example, but:
    // a) The widget plugin must exist, because on Component::save() we actually
    // create an instance for calculating configuration dependencies.
    // b) It's overkill to create an actual widget just for testing this.
    $settings['prop_field_definitions']['required_text']['field_widget'] = 'text_textfield';
    $source = $this->container->get(ComponentSourceManager::class)->createInstance(JsComponent::SOURCE_PLUGIN_ID, [
      'local_source_id' => $this->jsComponent->id(),
      ...$settings,
    ]);
    \assert($source instanceof ComponentSourceInterface);
    $new_version = $source->generateVersionHash();
    $component->createVersion($new_version)
      ->setSettings($settings);
    $component->save();
    self::assertCount(2, $component->getVersions());
  }

  protected function changeExpression(): void {
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId($this->jsComponent->id()));
    \assert($component instanceof Component);
    self::assertCount(1, $component->getVersions());
    $settings = $component->getSettings();
    self::assertSame('ℹ︎entity_reference␟entity␜␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}', $settings['prop_field_definitions']['background']['expression']);
    // Update the expression to drop "alt", which is an optional key-value pair
    // in `$ref: json-schema-definitions://canvas.module/image`. What matters is
    // that the same field data is still compatible with the new expression.
    $settings['prop_field_definitions']['background']['expression'] = 'ℹ︎entity_reference␟entity␜␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,width↠width,height↠height}';
    $source = $this->container->get(ComponentSourceManager::class)->createInstance(JsComponent::SOURCE_PLUGIN_ID, [
      'local_source_id' => $this->jsComponent->id(),
      ...$settings,
    ]);
    \assert($source instanceof ComponentSourceInterface);
    $new_version = $source->generateVersionHash();
    $component->createVersion($new_version)
      ->setSettings($settings);
    $component->save();
    self::assertCount(2, $component->getVersions());
  }

  protected function createNewImageMediaType(): void {
    $this->createMediaType('image', ['id' => 'vacation_photos']);

    // Trigger a cache write in PropShapeRepository — this happens on kernel
    // shutdown normally, but in a test we need to call it manually.
    $propShapeRepository = $this->container->get(PropShapeRepositoryInterface::class);
    self::assertInstanceOf(PersistentPropShapeRepository::class, $propShapeRepository);
    $propShapeRepository->destruct();

    // Re-trigger ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter().
    $this->container->get(ComponentSourceManager::class)->generateComponents(
      JsComponent::SOURCE_PLUGIN_ID,
      [$this->jsComponent->id()]
    );

    // The Component will now have been updated with a new version that includes
    // the new image media type.
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId($this->jsComponent->id()));
    \assert($component instanceof Component);
    self::assertCount(2, $component->getVersions());
    self::assertSame(['baby_photos' => 'baby_photos'], $component->getSettings(self::ORIGINAL_VERSION_HASH)['prop_field_definitions']['background']['field_instance_settings']['handler_settings']['target_bundles']);
    self::assertSame('ℹ︎entity_reference␟entity␜␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}', $component->getSettings(self::ORIGINAL_VERSION_HASH)['prop_field_definitions']['background']['expression']);
    self::assertSame(['baby_photos' => 'baby_photos', 'vacation_photos' => 'vacation_photos'], $component->getSettings()['prop_field_definitions']['background']['field_instance_settings']['handler_settings']['target_bundles']);
    self::assertSame('ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}][␜entity:media:vacation_photos␝field_media_image_1␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}]', $component->getSettings()['prop_field_definitions']['background']['expression']);
  }

  protected function addSlot(): void {
    $slots = $this->jsComponent->get('slots');
    $slots['new-slot'] = [
      'title' => 'New Slot',
      'description' => 'New Slot Description',
      'examples' => [
        'Contents of my new slot',
      ],
    ];
    $this->jsComponent->set('slots', $slots)
      ->save();
  }

  protected function removeSlot(): void {
    $this->jsComponent->set('slots', [])
      ->save();
  }

  protected function changeExamplesFromSlot(): void {
    $slots = $this->jsComponent->get('slots');
    $slots['test-slot']['examples'] = [
      'A brand new example for a slot',
    ];
    $this->jsComponent->set('slots', $slots)
      ->save();
  }

}
