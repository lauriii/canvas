<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\ComponentTreeItemInstantiatorTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests JavascriptComponentStorage.
 *
 * @legacy-covers \Drupal\canvas\EntityHandlers\JavascriptComponentStorage
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery
 */
#[RunTestsInSeparateProcesses]
#[Group('JavaScriptComponents')]
#[Group('canvas')]
final class JavascriptComponentStorageTest extends AssetLibraryStorageTest {

  use UserCreationTrait;
  use ConstraintViolationsTestTrait;
  use GenerateComponentConfigTrait;
  use ImageFieldCreationTrait;
  use ComponentTreeItemInstantiatorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'news_item', 'name' => 'News item'])->save();
    $this->createImageField('field_photo', 'node', 'news_item');
  }

  /**
   * Tests generated files.
   *
   * @legacy-covers \Drupal\canvas\EntityHandlers\CanvasAssetStorage::generateFiles
   */
  public function testGeneratedFiles(): void {
    $js_component = JavaScriptComponent::create([
      'machineName' => $this->randomMachineName(),
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => [],
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
      'dataDependencies' => [],
    ]);
    $this->assertGeneratedFiles($js_component);
  }

  public function testComponentEntityCreation(): array {
    $js_component_id = $this->randomMachineName();
    $component_id = JsComponent::componentIdFromJavascriptComponentId($js_component_id);
    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);

    // When the JS component does not exist, nor should the component config
    // entity.
    $component = Component::load($component_id);
    self::assertNull($component);

    // Now let's create the JavaScript component.
    // Should fail - missing examples.
    $props = [
      'title' => [
        'type' => 'string',
        'title' => 'Title',
      ],
    ];
    $js_component = JavaScriptComponent::create([
      'machineName' => $js_component_id,
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => $props,
      'required' => ['title'],
      'slots' => [],
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
      'dataDependencies' => [],
    ]);
    $this->assertSame([
      '' => 'Prop "title" is required, but does not have example value',
    ], self::violationsToArray($js_component->getTypedData()->validate()));

    // Make it pass validation by adding the missing `examples`, and save it.
    $props['title']['examples'] = ['Title'];
    $js_component->setProps($props);
    self::assertEntityIsValid($js_component);
    $js_component->save();

    // No Component config entity is ever created for JavaScript Components not
    // explicitly flagged to be added to Canvas's component library.
    $component = Component::load($component_id);
    self::assertEmpty($reason_repository->getReasons()[JsComponent::SOURCE_PLUGIN_ID] ?? []);
    self::assertNull($component);

    // Use a non-storable prop shape. The JavaScript Component config entity's
    // config schema SHOULD prevent the component author from choosing props
    // that the Drupal Canvas cannot generate an input UX for.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase
    // @see the `Choice` constraints on `type: canvas.js_component.*`'s for prop `format`.
    $props['title']['format'] = 'hostname';
    $js_component->setProps($props);
    $this->assertSame([
      '' => 'Drupal Canvas does not know of a field type/widget to allow populating the <code>title</code> prop, with the shape <code>{"type":"string","format":"hostname"}</code>.',
      'props.title.format' => 'The value you selected is not a valid choice.',
    ], self::violationsToArray($js_component->getTypedData()->validate()));
    // @see the `Choice` constraints on `type: canvas.js_component.*`'s for prop `type`.
    unset($props['title']['format']);
    $props['title']['type'] = 'null';
    $js_component->setProps($props);
    $this->assertSame([
      '' => 'Prop "title" has invalid example value: [] String value found, but a null or an object is required',
      'props.title.type' => 'The value you selected is not a valid choice.',
    ], self::violationsToArray($js_component->getTypedData()->validate()));

    // In other words: if the JavaScript Component config entity is sufficiently
    // tightly validated, the following should always be true.
    self::assertSame([], $reason_repository->getReasons()[JsComponent::SOURCE_PLUGIN_ID] ?? []);

    // Now remove the attempts to bypass the JavaScriptComponent config entity's
    // validation, enable it and verify that a corresponding Component config
    // entity is created.
    $props['title']['type'] = 'string';
    $js_component
      ->setProps($props)
      ->enable()
      ->save();

    $component = Component::load($component_id);
    self::assertInstanceOf(ComponentInterface::class, $component);
    self::assertNull($component->get('provider'));
    self::assertEquals(['title'], \array_keys($component->getSettings()['prop_field_definitions']));

    // Now update the js component and confirm we update the matching component.
    $props['noodles'] = [
      'type' => 'string',
      'title' => 'What sort of noodles do you like?',
      'examples' => ['Soba', 'Wheat', 'Pool'],
    ];
    $new_name = 'Will you accept my name?';
    $js_component->set('name', $new_name);
    $js_component->setProps($props)->save();

    $component = $this->loadComponent($component_id);
    self::assertEquals($new_name, $component->label());
    self::assertEquals(['title', 'noodles'], \array_keys($component->getSettings()['prop_field_definitions']));

    // Add two content-entity-reference props: a bundleless one (`entity:user`)
    // and a bundled one (`entity:node:news_item`). The `dataDependencies.entityFields`
    // entries are the single source of truth for the target entity type and
    // bundle — the prop definitions themselves MUST NOT carry `x-allowed-*`
    // keys.
    // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef::ContentEntityReference
    // @see \Drupal\canvas\Entity\JavaScriptComponent::toSdcDefinition()
    $props['fan'] = [
      'title' => 'Who is the fan?',
      ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
    ];
    $props['featured_news'] = [
      'title' => 'Featured news item',
      ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
    ];
    $js_component
      ->setProps($props)
      ->set('dataDependencies', [
        'entityFields' => [
          'fan' => ['ℹ︎␜entity:user␝name␞␟value'],
          'featured_news' => ['ℹ︎␜entity:node:news_item␝title␞␟value'],
        ],
      ])
      ->save();

    // The auto-generated Component config entity's `prop_field_definitions`
    // should now include both new content-entity-reference props as keys.
    $component = $this->loadComponent($component_id);
    self::assertEquals(
      ['title', 'noodles', 'fan', 'featured_news'],
      \array_keys($component->getSettings()['prop_field_definitions']),
    );

    // The projected SDC definition must inject `x-allowed-entity-type-id` for
    // both props and `x-allowed-bundle` for the bundled one, under the
    // `props.properties.<prop>` path. The persisted config entity's props must
    // NOT carry these keys.
    $sdc_definition = $js_component->toSdcDefinition();
    self::assertSame('user', $sdc_definition['props']['properties']['fan']['x-allowed-entity-type-id']);
    self::assertArrayNotHasKey('x-allowed-bundle', $sdc_definition['props']['properties']['fan']);
    self::assertSame('node', $sdc_definition['props']['properties']['featured_news']['x-allowed-entity-type-id']);
    self::assertSame('news_item', $sdc_definition['props']['properties']['featured_news']['x-allowed-bundle']);
    // The persisted config entity's props are untouched by the projection.
    $persisted_props = $js_component->getProps();
    \assert(\is_array($persisted_props));
    self::assertArrayNotHasKey('x-allowed-entity-type-id', $persisted_props['fan']);
    self::assertArrayNotHasKey('x-allowed-bundle', $persisted_props['fan']);
    self::assertArrayNotHasKey('x-allowed-entity-type-id', $persisted_props['featured_news']);
    self::assertArrayNotHasKey('x-allowed-bundle', $persisted_props['featured_news']);

    // Check idempotency: repeated `toSdcDefinition()` calls
    // must return identical arrays. The projection mutates a local copy of
    // the prop definitions; `$this->props` must NEVER be touched.
    self::assertSame($sdc_definition, $js_component->toSdcDefinition());

    return $js_component->toArray();
  }

  /**
   * Tests component entity update.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery::computeCurrentComponentMetadata()
   */
  #[Depends('testComponentEntityCreation')]
  public function testComponentEntityUpdate(array $js_component_values): void {
    $js_component = JavaScriptComponent::create($js_component_values);
    $js_component->save();
    \assert(\is_string($js_component->id()));
    $component_id = JsComponent::componentIdFromJavascriptComponentId($js_component->id());

    // Name should carry over.
    $new_name = $js_component->label() . ' — updated';
    $js_component->set('name', $new_name)->save();
    $this->assertSame($new_name, $this->loadComponent($component_id)->label());

    // Status should carry over.
    $this->assertTrue($js_component->status());
    $this->assertTrue($this->loadComponent($component_id)->status());
    $js_component->disable()->save();
    $this->assertFalse($js_component->status());
    $this->assertFalse($this->loadComponent($component_id)->status());
    $js_component->enable()->save();
    $this->assertTrue($js_component->status());
    $this->assertTrue($this->loadComponent($component_id)->status());

    // Sanity check: at this point the JS component still carries the content-
    // entity-reference props that `testComponentEntityCreation()` added
    // (`fan` + `featured_news`). The auto-generated Component must reflect
    // them in `prop_field_definitions`.
    $component = $this->loadComponent($component_id);
    self::assertEqualsCanonicalizing(
      ['title', 'noodles', 'fan', 'featured_news'],
      \array_keys($component->getSettings()['prop_field_definitions']),
    );
    self::assertCount(1, $component->getVersions());

    // Remove the bundle-less `fan` content-entity-reference prop.
    // After save, the auto-generated Component must drop `fan` from
    // `prop_field_definitions` and the projected SDC definition must no
    // longer carry `fan` under `props.properties`.
    // Be aware this is the JavaScriptComponent config entity: its Component config
    // entity will still generate a new version and not override it.
    $props = $js_component->getProps();
    \assert(\is_array($props));
    unset($props['fan']);
    $data_dependencies = $js_component->get('dataDependencies');
    \assert(\is_array($data_dependencies));
    unset($data_dependencies['entityFields']['fan']);
    $js_component
      ->setProps($props)
      ->set('dataDependencies', $data_dependencies)
      ->save();

    // Its Component config entity has been updated with a new version.
    $component = $this->loadComponent($component_id);
    self::assertCount(2, $component->getVersions());
    $prop_field_definitions = $component->getSettings()['prop_field_definitions'];
    self::assertArrayNotHasKey('fan', $prop_field_definitions);
    self::assertEqualsCanonicalizing(
      ['title', 'noodles', 'featured_news'],
      \array_keys($prop_field_definitions),
    );
    $sdc_definition = $js_component->toSdcDefinition();
    self::assertArrayNotHasKey('fan', $sdc_definition['props']['properties']);

    // Switch `featured_news`'s target bundle from `news_item` to a brand-new
    // `breaking_news` NodeType. After save, both the projected SDC definition
    // AND the auto-generated Component's `prop_field_definitions` (which
    // derives `target_bundles` via `JsonSchemaType::Object`) must reflect the
    // new bundle.
    NodeType::create(['type' => 'breaking_news', 'name' => 'Breaking news'])->save();
    $data_dependencies['entityFields']['featured_news'] = ['ℹ︎␜entity:node:breaking_news␝title␞␟value'];
    $js_component->set('dataDependencies', $data_dependencies)->save();

    $sdc_definition = $js_component->toSdcDefinition();
    self::assertSame(
      'breaking_news',
      $sdc_definition['props']['properties']['featured_news']['x-allowed-bundle'],
    );

    $component = $this->loadComponent($component_id);
    $prop_field_definitions = $component->getSettings()['prop_field_definitions'];
    self::assertArrayHasKey('featured_news', $prop_field_definitions);
    self::assertSame(
      ['breaking_news'],
      $prop_field_definitions['featured_news']['field_instance_settings']['handler_settings']['target_bundles'],
    );
  }

  /**
   * Renders a `HostEntityPropSource` against a fieldable host entity.
   *
   * Exercises the matcher → suggester → storage → render pipeline end-to-end:
   * a code component declares a content-entity-reference prop whose stored
   * input is `['sourceType' => 'host-entity']`; at render time the parent's
   * parse/evaluate loop resolves it to the host entity, and `JsComponent`
   * unfurls it through `dataDependencies.entityFields` into a developer-facing
   * payload keyed by entity-key-mapped field names (e.g. `title` → `label`).
   */
  public function testHostEntityPropSourceResolvesAgainstHost(): void {
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser([], ['access content']);

    $machine_name = 'host_entity_render_test';
    $component_id = JsComponent::componentIdFromJavascriptComponentId($machine_name);
    $js_component = JavaScriptComponent::create([
      'machineName' => $machine_name,
      'name' => 'Host entity render test',
      'status' => TRUE,
      'props' => [
        'host_node' => [
          'title' => 'Host node',
          ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [
        'entityFields' => [
          'host_node' => ['ℹ︎␜entity:node:news_item␝title␞␟value'],
        ],
      ],
    ]);
    self::assertEntityIsValid($js_component);
    $js_component->save();

    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(JsComponent::class, $source);

    $host_node = Node::create([
      'type' => 'news_item',
      'title' => 'The host news item',
    ]);
    self::assertEntityIsValid($host_node);
    $host_node->save();

    $item = $this->buildComponentTreeItem($component_id, [
      'host_node' => ['sourceType' => 'host-entity'],
    ]);
    $uuid = $this->container->get('uuid')->generate();
    $result = $source->getExplicitInput($uuid, $item, $host_node);

    self::assertSame(['source', 'resolved'], \array_keys($result));
    self::assertSame(
      ['sourceType' => 'host-entity'],
      $result['source']['host_node'],
    );
    self::assertInstanceOf(EvaluationResult::class, $result['resolved']['host_node']);
    self::assertSame(['label' => 'The host news item'], $result['resolved']['host_node']->value);
  }

  /**
   * Asserts `HostEntityPropSource` throws when no host entity is available.
   */
  public function testHostEntityPropSourceThrowsWhenHostMissing(): void {
    $machine_name = 'host_entity_render_test_no_host';
    $component_id = JsComponent::componentIdFromJavascriptComponentId($machine_name);
    $js_component = JavaScriptComponent::create([
      'machineName' => $machine_name,
      'name' => 'Host entity render test (no host)',
      'status' => TRUE,
      'props' => [
        'host_node' => [
          'title' => 'Host node',
          ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
        ],
      ],
      'required' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [
        'entityFields' => [
          'host_node' => ['ℹ︎␜entity:node:news_item␝title␞␟value'],
        ],
      ],
    ]);
    self::assertEntityIsValid($js_component);
    $js_component->save();

    $component = Component::load($component_id);
    self::assertInstanceOf(Component::class, $component);
    $source = $component->getComponentSource();
    self::assertInstanceOf(JsComponent::class, $source);

    $item = $this->buildComponentTreeItem($component_id, [
      'host_node' => ['sourceType' => 'host-entity'],
    ]);
    $uuid = $this->container->get('uuid')->generate();

    $this->expectException(MissingHostEntityException::class);
    $source->getExplicitInput($uuid, $item, NULL);
  }

  private function loadComponent(string $id): Component {
    // @phpstan-ignore-next-line
    return $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->loadUnchanged($id);
  }

}
