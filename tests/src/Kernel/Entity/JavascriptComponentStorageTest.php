<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests JavascriptComponentStorage.
 *
 * @covers \Drupal\experience_builder\EntityHandlers\JavascriptComponentStorage
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent
 * @group JavaScriptComponents
 * @group experience_builder
 */
final class JavascriptComponentStorageTest extends KernelTestBase {

  use UserCreationTrait;
  use ContribStrictConfigSchemaTestTrait;
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
   * Covers component creation.
   */
  public function testComponentEntityCreation(): void {
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
      'props.title.format' => 'The value you selected is not a valid choice.',
    ], self::violationsToArray($js_component->getTypedData()->validate()));
    // @see the `Choice` constraints on `type: experience_builder.js_component.*`'s for prop `type`.
    unset($props['title']['format']);
    $props['title']['type'] = 'object';
    $js_component->setProps($props);
    $this->assertSame([
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
    $js_component->setProps($props)->save();

    $component = \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->loadUnchanged($component_id);
    \assert($component instanceof ComponentInterface);
    self::assertEquals(['noodles', 'title'], \array_keys($component->getSettings()['prop_field_definitions']));
  }

}
