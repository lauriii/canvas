<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Field\FieldType;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\DataType\ResolvedComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\jsonapi\Normalizer\FieldItemNormalizer;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the 'inputs_resolved' computed property on ComponentTreeItem.
 */
#[CoversClass(ResolvedComponentInputs::class)]
#[CoversClass(ComponentTreeItem::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class ResolvedComponentInputsTest extends CanvasKernelTestBase {

  use ComponentTreeItemListInstantiatorTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'jsonapi',
    'node',
    'serialization',
    'canvas_test_code_components',
  ];

  /**
   * The component tree item under test.
   */
  private ComponentTreeItem $item;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();

    $item_list = $this->createDanglingComponentTreeItemList();
    $item_list->setValue([
      [
        'uuid' => '947c196f-f108-43fd-a446-03a08100d571',
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'inputs' => [
          'heading' => 'Test heading',
        ],
      ],
    ]);
    $item = $item_list->get(0);
    \assert($item instanceof ComponentTreeItem);
    $this->item = $item;
  }

  /**
   * Tests the component instance's resolved inputs.
   */
  public function testResolvedComponentInputs(): void {
    // First access: should return original value.
    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame(['heading' => 'Test heading'], $resolved);

    // Change inputs: resolved should be invalidated and return new value.
    $this->item->set('inputs', ['heading' => 'Updated heading']);

    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame(['heading' => 'Updated heading'], $resolved);

    // Delete the Component config entity so it can no longer be loaded.
    $component = Component::load('sdc.canvas_test_sdc.props-slots');
    $this->assertNotNull($component);
    $component->delete();

    // The cache is now stale, but 'inputs_resolved' should still return the
    // original value.
    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame(['heading' => 'Updated heading'], $resolved);

    // Force re-evaluation by updating the component ID.
    $this->item->set('component_id', 'sdc.canvas_test_sdc.props-slots');

    // The Component config entity cannot be loaded, so 'inputs_resolved' should return NULL.
    $this->assertNull($this->item->get('inputs_resolved')->getValue());
  }

  /**
   * Tests the component instance's resolved inputs for a block.
   */
  public function testResolvedComponentInputsForBlock(): void {
    $item_list = $this->createDanglingComponentTreeItemList();
    $item_list->setValue([
      [
        'uuid' => '947c196f-f108-43fd-a446-03a08100d571',
        'component_id' => 'block.system_branding_block',
        'inputs' => [
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => TRUE,
          'label' => '',
          'label_display' => '0',
        ],
      ],
    ]);
    $item = $item_list->get(0);
    \assert($item instanceof ComponentTreeItem);
    $this->item = $item;

    // First access: should return original value.
    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame([
      'use_site_logo' => TRUE,
      'use_site_name' => TRUE,
      'use_site_slogan' => TRUE,
      'label' => '',
      'label_display' => '0',
    ], $resolved);

    // Change inputs: resolved should be invalidated and return new value.
    $this->item->set('inputs', [
      'use_site_logo' => TRUE,
      'use_site_name' => FALSE,
      'use_site_slogan' => FALSE,
      'label' => '',
      'label_display' => '0',
    ]);

    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame([
      'use_site_logo' => TRUE,
      'use_site_name' => FALSE,
      'use_site_slogan' => FALSE,
      'label' => '',
      'label_display' => '0',
    ], $resolved);

    // Delete the Component config entity so it can no longer be loaded.
    $component = Component::load('block.system_branding_block');
    $this->assertNotNull($component);
    $component->delete();

    // The cache is now stale, but 'inputs_resolved' should still return the
    // original value.
    $resolved = $this->item->get('inputs_resolved')->getValue();
    $this->assertSame([
      'use_site_logo' => TRUE,
      'use_site_name' => FALSE,
      'use_site_slogan' => FALSE,
      'label' => '',
      'label_display' => '0',
    ], $resolved);

    // Force re-evaluation by updating the component ID.
    $this->item->set('component_id', 'block.system_branding_block');

    // The Component config entity cannot be loaded, so 'inputs_resolved' should return NULL.
    $this->assertNull($this->item->get('inputs_resolved')->getValue());
  }

  /**
   * Tests that 'inputs_resolved' is exposed via JSON:API normalization.
   *
   * @see \Drupal\jsonapi\Normalizer\FieldItemNormalizer::doNormalize()
   */
  public function testInputsResolvedExposedViaJsonApi(): void {
    $this->installEntitySchema('user');

    $normalizer = new FieldItemNormalizer($this->container->get('entity_type.manager'));
    $normalizer->setSerializer($this->container->get('jsonapi.serializer'));

    // @phpstan-ignore-next-line return.type
    $page = Page::create()->setComponentTree([$this->item->toArray()]);
    $result = $normalizer->normalize($page->getComponentTree()->get(0), 'api_json');
    $this->assertInstanceOf(CacheableNormalization::class, $result);
    $normalized = $result->getNormalization();

    \assert(\is_array($normalized));
    $this->assertArrayHasKey('inputs_resolved', $normalized);
    $this->assertSame(['heading' => 'Test heading'], $normalized['inputs_resolved']);

    // Computed internal properties should NOT be in the normalized output.
    $this->assertArrayNotHasKey('component', $normalized);
    $this->assertArrayNotHasKey('parent_item', $normalized);
  }

}
