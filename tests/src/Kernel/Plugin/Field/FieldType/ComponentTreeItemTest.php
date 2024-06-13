<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\Field\FieldType;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests dependency calculation in ComponentTreeItem.
 *
 * @group experience_builder
 */
class ComponentTreeItemTest extends KernelTestBase {

  const DEFAULT_VALUE = [
    'tree' => '[{"uuid":"dynamic-image-udf7d","type":"experience_builder:image"},{"uuid":"static-static-card1ab","type":"sdc_test:my-cta"},{"uuid":"dynamic-static-card2df","type":"sdc_test:my-cta"},{"uuid":"dynamic-dynamic-card3rr","type":"sdc_test:my-cta"},{"uuid":"dynamic-image-static-imageStyle-something7d","type":"experience_builder:image"}]',
    // cspell:disable-next-line
    'props' => '{"dynamic-static-card2df":{"text":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:article\u241dtitle\u241e\u241fvalue"},"href":{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"\u2139\ufe0euri\u241fvalue"}},"static-static-card1ab":{"text":{"sourceType":"static:field_item:string","value":"hello, world!","expression":"\u2139\ufe0estring\u241fvalue"},"href":{"sourceType":"static:field_item:uri","value":"https:\/\/drupal.org","expression":"\u2139\ufe0euri\u241fvalue"}},"dynamic-dynamic-card3rr":{"text":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:article\u241dtitle\u241e\u241fvalue"},"href":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:article\u241dfield_hero\u241e\u241fentity\u241c\u241centity:file\u241duri\u241e\u241fvalue"}},"dynamic-image-udf7d":{"image":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:article\u241dfield_hero\u241e\u241f{src\u219dentity\u241c\u241centity:file\u241duri\u241e\u241fvalue,alt\u21a0alt,width\u21a0width,height\u21a0height}"}},"dynamic-image-static-imageStyle-something7d":{"image":{"sourceType":"adapter:image_apply_style","adapterInputs":{"image":{"sourceType":"dynamic","expression":"\u2139\ufe0e\u241centity:node:article\u241dfield_hero\u241e\u241f{src\u219dentity\u241c\u241centity:file\u241duri\u241e0\u241fvalue,alt\u21a0alt,width\u21a0width,height\u21a0height}"},"imageStyle":{"sourceType":"static:field_item:string","value":"thumbnail","expression":"\u2139\ufe0estring\u241fvalue"}}}}}',
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
    ])->save();
    Component::create([
      'label' => $this->randomString(),
      'component' => Component::convertMachineNameToId('sdc_test:my-cta'),
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
