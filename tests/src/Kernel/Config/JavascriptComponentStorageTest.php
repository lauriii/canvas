<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests JavascriptComponentStorage.
 *
 * @covers \Drupal\experience_builder\EntityHandlers\JavascriptComponentStorage
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::createConfigEntity
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::updateConfigEntity
 * @group JavaScriptComponents
 * @group experience_builder
 */
final class JavascriptComponentStorageTest extends AssetLibraryStorageTest {

  use UserCreationTrait;
  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'user',
    'system',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * @covers \Drupal\experience_builder\EntityHandlers\XbAssetStorage::generateFiles()
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
    ]);
    $this->assertGeneratedFiles($js_component);
  }

  /**
   * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::createConfigEntity()
   */
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
    ]);
    $this->assertSame([
      'props.title' => "'examples' is a required key.",
    ], self::violationsToArray($js_component->getTypedData()->validate()));

    // Make it pass validation by adding the missing `examples`, and save it.
    $props['title']['examples'] = ['Title'];
    $js_component->setProps($props);
    $this->assertSame([], self::violationsToArray($js_component->getTypedData()->validate()));
    $js_component->save();

    // No Component config entity is ever created for JavaScript Components not
    // explicitly flagged to be added to XB's component library.
    $component = Component::load($component_id);
    self::assertEmpty($reason_repository->getReasons()[JsComponent::SOURCE_PLUGIN_ID] ?? []);
    self::assertNull($component);

    // Use a non-storable prop shape. The JavaScript Component config entity's
    // config schema SHOULD prevent the component author from choosing props
    // that the Experience Builder cannot generate an input UX for.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
    // @see the `Choice` constraints on `type: experience_builder.js_component.*`'s for prop `format`.
    $props['title']['format'] = 'hostname';
    $js_component->setProps($props);
    $this->assertSame([
      'props.title' => 'Prop "<em class="placeholder">title</em>" has a shape that is unfortunately not supported by Experience Builder.',
      'props.title.format' => 'The value you selected is not a valid choice.',
    ], self::violationsToArray($js_component->getTypedData()->validate()));
    // @see the `Choice` constraints on `type: experience_builder.js_component.*`'s for prop `type`.
    unset($props['title']['format']);
    $props['title']['type'] = 'array';
    $js_component->setProps($props);
    $this->assertSame([
      'props.title' => 'Prop "<em class="placeholder">title</em>" has a shape that is unfortunately not supported by Experience Builder.',
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
    self::assertEquals(['noodles', 'title'], \array_keys($component->getSettings()['prop_field_definitions']));

    return $js_component->toArray();
  }

  /**
   * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::updateConfigEntity()
   * @depends testComponentEntityCreation
   */
  public function testComponentEntityUpdate(array $js_component_values): void {
    $js_component = JavaScriptComponent::create($js_component_values);
    $js_component->save();
    assert(is_string($js_component->id()));
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
  }

  private function loadComponent(string $id): Component {
    // @phpstan-ignore-next-line
    return $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->loadUnchanged($id);
  }

}
