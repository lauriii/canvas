<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

class ComponentTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

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
    // Modules providing field types + widgets for the component props defaults.
    'image',
    'options',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['sdc_theme_test']);
  }

  public function testMachineNameAndIdConversion(): void {
    // @todo This confusing because in both cases both the input and output are something we call an "ID" but
    //   Then it is supposed to be conversion to/from a "machine name".
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, Component::convertMachineNameToId(self::MODULE_COMPONENT_ID));
    $this->assertSame(self::MODULE_COMPONENT_ID, Component::convertIdToMachineName(self::MODULE_CONFIG_ENTITY_ID));
  }

  public function testComponentCreation(): void {
    $this->assertEmpty(Component::loadMultiple());

    $module_component = Component::create([
      'component' => self::MODULE_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
      'defaults' => [
        'props' => [
          'text' => [
            // @see \Drupal\Core\Field\Plugin\Field\FieldType\StringItem
            'field_type' => 'string',
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget
            'field_widget' => 'string_textfield',
            'default_value' => ['value' => 'Hello, world!'],
            'expression' => 'ℹ︎string␟value',
          ],
          'href' => [
            // @see \Drupal\Core\Field\Plugin\Field\FieldType\UriItem
            'field_type' => 'uri',
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\UriWidget
            'field_widget' => 'uri',
            'default_value' => ['value' => 'https://drupal.org'],
            'expression' => 'ℹ︎uri␟value',
          ],
          'target' => [
            // @see \Drupal\options\Plugin\Field\FieldType\ListStringItem
            'field_type' => 'list_string',
            'field_storage_settings' => [
              'allowed_values' => [
                ['value' => 'foo', 'label' => 'foo'],
                ['value' => 'bar', 'label' => 'bar'],
              ],
            ],
            // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget
            'field_widget' => 'options_select',
            'default_value' => NULL,
            'expression' => 'ℹ︎list_string␟value',
          ],
        ],
      ],
    ]);
    $module_component->save();

    $this->assertNotEmpty(Component::loadMultiple());
    $this->assertSame(['module' => ['options', 'sdc_test']], $module_component->getDependencies());
    $this->assertSame(self::MODULE_COMPONENT_ID, $module_component->getComponentMachineName());
    $this->assertSame(self::MODULE_CONFIG_ENTITY_ID, $module_component->id());

    $text_default_static_prop_source = $module_component->getDefaultStaticPropSource('text');
    $this->assertInstanceOf(StaticPropSource::class, $text_default_static_prop_source);
    $this->assertSame('static:field_item:string', $text_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(StringTextfieldWidget::class, $text_default_static_prop_source->getWidget('text', NULL));
    $this->assertSame('{"sourceType":"static:field_item:string","value":"Hello, world!","expression":"ℹ︎string␟value"}', (string) $text_default_static_prop_source);

    $href_default_static_prop_source = $module_component->getDefaultStaticPropSource('href');
    $this->assertInstanceOf(StaticPropSource::class, $href_default_static_prop_source);
    $this->assertSame('static:field_item:uri', $href_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(UriWidget::class, $href_default_static_prop_source->getWidget('href', NULL));
    $this->assertSame('{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}', (string) $href_default_static_prop_source);

    $target_default_static_prop_source = $module_component->getDefaultStaticPropSource('target');
    $this->assertInstanceOf(StaticPropSource::class, $target_default_static_prop_source);
    $this->assertSame('static:field_item:list_string', $target_default_static_prop_source->getSourceType());
    $this->assertInstanceOf(OptionsSelectWidget::class, $target_default_static_prop_source->getWidget('target', NULL));
    $this->assertSame('{"sourceType":"static:field_item:list_string","value":null,"expression":"ℹ︎list_string␟value","sourceTypeSettings":{"allowed_values":[{"value":"foo","label":"foo"},{"value":"bar","label":"bar"}]}}', (string) $target_default_static_prop_source);

    $theme_component = Component::create([
      'component' => self::THEME_CONFIG_ENTITY_ID,
      'label' => self::LABEL,
      'defaults' => [
        'props' => [],
      ],
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
