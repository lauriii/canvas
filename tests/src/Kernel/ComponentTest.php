<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\experience_builder\Entity\Component;
use Drupal\KernelTests\KernelTestBase;

class ComponentTest extends KernelTestBase {

  const MODULE_COMPONENT_ID = 'sdc_test:my-cta';
  const MODULE_CONFIG_ENTITY_ID = 'sdc_test+my-cta';
  const THEME_COMPONENT_ID = 'sdc_theme_test:bar';
  const THEME_CONFIG_ENTITY_ID = 'sdc_theme_test+bar';
  const MISSING_COMPONENT_ID = 'experience_builder:missing-component';
  const LABEL = 'Test Component';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['sdc_theme_test']);
  }

  public function testMachineNameAndIdConversion(): void {
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, Component::convertMachineNameToId(self::MODULE_COMPONENT_ID));
    $this->assertSame(self::MODULE_COMPONENT_ID, Component::convertIdToMachineName(self::MODULE_CONFIG_ENTITY_ID));
  }

  public function testComponentCreation(): void {
    $this->assertEmpty(Component::loadMultiple());

    $module_component = Component::create([
      'component' => self::MODULE_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
    ]);
    $module_component->save();

    $this->assertNotEmpty(Component::loadMultiple());
    $this->assertSame(['module' => ['sdc_test']], $module_component->getDependencies());
    $this->assertSame(self::MODULE_COMPONENT_ID, $module_component->getComponentMachineName());
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, $module_component->id());

    $theme_component = Component::create([
      'component' => self::THEME_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
    ]);
    $theme_component->save();

    $this->assertSame(['theme' => ['sdc_theme_test']], $theme_component->getDependencies());
    $this->assertSame(self::THEME_COMPONENT_ID, $theme_component->getComponentMachineName());
    $this->assertSame(self::THEME_CONFIG_ENTITY_ID, $theme_component->id());
  }

  /**
   * Tests ComponentNotFoundException thrown when saving entity with machine name referring to component that can't be located.
   */
  public function testMissingComponentDependency(): void {
    $message = sprintf('Unable to find component "%s" in the component repository.', self::MISSING_COMPONENT_ID);
    $this->expectExceptionObject(new ComponentNotFoundException($message));
    Component::create([
      'component' => self::MISSING_COMPONENT_ID,
      'label' => self::LABEL,
    ])->save();
  }

}
