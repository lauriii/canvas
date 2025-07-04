<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Tests JavascriptComponent access.
 *
 * @group experience_builder
 * @covers \Drupal\experience_builder\Entity\JavaScriptComponent
 * @covers \Drupal\experience_builder\EntityHandlers\XbConfigEntityAccessControlHandler
 */
final class JavascriptComponentAccessTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'experience_builder',
    'user',
    'system',
    'datetime',
    'file',
    'image',
    'options',
    'path',
    'link',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  public function testAccess(): void {
    $js_component_id = $this->randomMachineName();
    $slots = [
      'slot1' => [
        'title' => 'Slot 1',
        'description' => 'Slot 1 innit.',
      ],
    ];
    $js_component = JavaScriptComponent::create([
      'machineName' => $js_component_id,
      'name' => $this->getRandomGenerator()->sentences(5),
      'status' => FALSE,
      'props' => [],
      'required' => [],
      'slots' => $slots,
      'js' => [
        'original' => 'console.log("hey");',
        'compiled' => 'console.log("hey");',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test { display: none; }',
      ],
    ]);
    self::assertCount(0, $js_component->getTypedData()->validate());
    $js_component->save();
    $code_component_maintainer = $this->createUser([JavaScriptComponent::ADMIN_PERMISSION]);
    \assert($code_component_maintainer instanceof UserInterface);
    self::assertTrue($js_component->access('delete', $code_component_maintainer));

    // Now enable the component.
    $js_component->enable()->save();
    // And reset the access cache.
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $entity_type_manager->getAccessControlHandler(JavaScriptComponent::ENTITY_TYPE_ID)->resetCache();
    $component_id = JsComponent::componentIdFromJavascriptComponentId($js_component_id);
    $component = Component::load($component_id);
    self::assertNotNull($component);
    self::assertTrue($component->status());
    self::assertContains($js_component->getConfigDependencyName(), $component->getDependencies()['config'] ?? []);
    self::assertCount(0, $js_component->getTypedData()->validate());
    // User should still have access to delete.
    self::assertTrue($js_component->access('delete', $code_component_maintainer));

    // Now instantiate the component.
    Pattern::create([
      'label' => $this->randomMachineName(),
      'component_tree' => [
        ['uuid' => 'uuid-in-root', 'component_id' => $component_id, 'inputs' => []],
      ],
    ])->save();
    // And reset the access cache.
    $entity_type_manager->getAccessControlHandler(JavaScriptComponent::ENTITY_TYPE_ID)->resetCache();
    // User should no longer have access to delete.
    self::assertFalse($js_component->access('delete', $code_component_maintainer));
  }

}
