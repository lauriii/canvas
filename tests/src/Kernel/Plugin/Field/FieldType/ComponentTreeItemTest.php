<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * Tests dependency calculation in ComponentTreeItem.
 *
 * @group experience_builder
 */
class ComponentTreeItemTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;

  const DEFAULT_VALUE = [
    'tree' => '{"' . ComponentTreeStructure::ROOT_UUID . '": [{"uuid":"dynamic-image-udf7d","component":"experience_builder:image"},{"uuid":"static-static-card1ab","component":"sdc_test:my-cta"},{"uuid":"dynamic-static-card2df","component":"sdc_test:my-cta"},{"uuid":"dynamic-dynamic-card3rr","component":"sdc_test:my-cta"},{"uuid":"dynamic-image-static-imageStyle-something7d","component":"experience_builder:image"}]}',
    'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}},"static-static-card1ab":{"text":{"sourceType":"static:field_item:string","value":"hello, world!","expression":"ℹ︎string␟value"},"href":{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"ℹ︎uri␟value"}},"dynamic-dynamic-card3rr":{"text":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝title␞␟value"},"href":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟entity␜␜entity:file␝uri␞␟value"}},"dynamic-image-udf7d":{"image":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}"}},"dynamic-image-static-imageStyle-something7d":{"image":{"sourceType":"adapter:image_apply_style","adapterInputs":{"image":{"sourceType":"dynamic","expression":"ℹ︎␜entity:node:article␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞0␟value,alt↠alt,width↠width,height↠height}"},"imageStyle":{"sourceType":"static:field_item:string","value":"thumbnail","expression":"ℹ︎string␟value"}}}}}',
  ];
  const EXPECTED_DEPENDENCIES = [
    'config' => ['experience_builder.component.experience_builder+image', 'experience_builder.component.sdc_test+my-cta'],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
    // Modules providing field types + widgets for the component props defaults.
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['sdc_theme_test']);
    Component::create([
      'label' => $this->randomString(),
      'component' => Component::convertMachineNameToId('experience_builder:image'),
      'defaults' => [
        'props' => [
          'image' => [
            // @see \Drupal\image\Plugin\Field\FieldType\ImageItem
            'field_type' => 'image',
            // @see \Drupal\image\Plugin\Field\FieldWidget\ImageWidget
            'field_widget' => 'image_image',
            'default_value' => NULL,
            'expression' => 'ℹ︎image␟image',
          ],
        ],
      ],
    ])->save();
    Component::create([
      'label' => $this->randomString(),
      'component' => Component::convertMachineNameToId('sdc_test:my-cta'),
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
            'field_type' => NULL,
            'field_widget' => NULL,
            'default_value' => NULL,
            'expression' => NULL,
          ],
        ],
      ],
    ])->save();
  }

  public function testCalculateDependencies(): void {
    $this->assertSame([], ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')));
    $this->assertSame(
      self::EXPECTED_DEPENDENCIES,
      ComponentTreeItem::calculateDependencies(BaseFieldDefinition::create('component_tree')->setDefaultValue(self::DEFAULT_VALUE))
    );
  }

}
