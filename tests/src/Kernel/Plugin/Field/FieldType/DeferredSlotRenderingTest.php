<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Field\FieldType;

use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\ComponentSource\ComponentSourceWithDeferredSlotsInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_test_deferred_slots\Plugin\Canvas\ComponentSource\TestDeferredSlots;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that deferred-slot sources receive their subtree unrendered.
 */
#[CoversClass(ComponentTreeItemList::class)]
#[Group('canvas')]
class DeferredSlotRenderingTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  private const string DEFERRED_UUID = '11111111-1111-4111-8111-111111111111';
  private const string CHILD_UUID = '22222222-2222-4222-8222-222222222222';
  private const string GRANDCHILD_UUID = '33333333-3333-4333-8333-333333333333';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Lifts the component source allowlist, exactly as canvas_personalization's
    // tests must.
    // @see https://www.drupal.org/i/3520484
    'canvas_dev_mode',
    'canvas_test_deferred_slots',
    'canvas_test_sdc',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->generateComponentConfig();
    // The test source uses no discovery, so create its Component entity here,
    // the way canvas_personalization does. The version hash must match what
    // the source computes, so compute it.
    $source = $this->container->get(ComponentSourceManager::class)
      ->createInstance(TestDeferredSlots::PLUGIN_ID, ['local_source_id' => 'the_component']);
    \assert($source instanceof ComponentSourceBase);
    Component::create([
      'id' => 'test_deferred.the_component',
      'label' => 'Test deferred',
      'source' => TestDeferredSlots::PLUGIN_ID,
      'source_local_id' => 'the_component',
      'active_version' => $source->generateVersionHash(),
    ])->save();
  }

  /**
   * A deferred source's descendants are handed over raw, not rendered.
   */
  public function testDeferredSubtreeIsHandedToTheSource(): void {
    $sdc_component = $this->loadComponent('sdc.canvas_test_sdc.props-slots');

    $tree = [
      [
        'uuid' => self::DEFERRED_UUID,
        'component_id' => 'test_deferred.the_component',
        'component_version' => $this->loadComponent('test_deferred.the_component')->getActiveVersion(),
        'inputs' => [],
      ],
      [
        'uuid' => self::CHILD_UUID,
        'component_id' => $sdc_component->id(),
        'component_version' => $sdc_component->getActiveVersion(),
        'parent_uuid' => self::DEFERRED_UUID,
        'slot' => TestDeferredSlots::SLOT_NAME,
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'DEFERRED CHILD MUST NOT RENDER',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
      [
        'uuid' => self::GRANDCHILD_UUID,
        'component_id' => $sdc_component->id(),
        'component_version' => $sdc_component->getActiveVersion(),
        'parent_uuid' => self::CHILD_UUID,
        'slot' => 'the_body',
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'GRANDCHILD MUST NOT RENDER EITHER',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
    ];

    TestDeferredSlots::$lastReceivedDeferredItems = NULL;
    $vehicle = Pattern::create([
      'id' => 'deferred_test',
      'label' => 'Deferred test',
      'component_tree' => $tree,
    ]);
    $build = $vehicle->getComponentTree()->toRenderable($vehicle, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);

    // The deferred source rendered, and reports both descendants.
    $this->assertStringContainsString('data-deferred-count="2"', $html);

    // Neither descendant was rendered by the tree.
    $this->assertStringNotContainsString('DEFERRED CHILD MUST NOT RENDER', $html);
    $this->assertStringNotContainsString('GRANDCHILD MUST NOT RENDER EITHER', $html);

    // The source received the raw stored values, in stored order, with parent
    // and slot references intact.
    $received = TestDeferredSlots::$lastReceivedDeferredItems;
    $this->assertIsArray($received);
    $this->assertCount(2, $received);
    $this->assertSame(self::CHILD_UUID, $received[0]['uuid']);
    $this->assertSame(self::DEFERRED_UUID, $received[0]['parent_uuid']);
    $this->assertSame(TestDeferredSlots::SLOT_NAME, $received[0]['slot']);
    $this->assertSame(self::GRANDCHILD_UUID, $received[1]['uuid']);
    $this->assertSame(self::CHILD_UUID, $received[1]['parent_uuid']);

    // The default slot value the source hydrates is discarded, and the tree
    // never calls setSlots() (the test source throws if it does).
    $this->assertStringNotContainsString('DEFAULT SLOT VALUE MUST BE DISCARDED', $html);
  }

  /**
   * A childless deferred root still receives the (empty) deferred items key.
   */
  public function testChildlessDeferredRoot(): void {
    TestDeferredSlots::$lastReceivedDeferredItems = NULL;
    $vehicle = Pattern::create([
      'id' => 'childless_test',
      'label' => 'Childless test',
      'component_tree' => [
        [
          'uuid' => self::DEFERRED_UUID,
          'component_id' => 'test_deferred.the_component',
          'component_version' => $this->loadComponent('test_deferred.the_component')->getActiveVersion(),
          'inputs' => [],
        ],
      ],
    ]);
    $build = $vehicle->getComponentTree()->toRenderable($vehicle, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $this->assertStringContainsString('data-deferred-count="0"', $html);
    $this->assertSame([], TestDeferredSlots::$lastReceivedDeferredItems);
    $this->assertStringNotContainsString('DEFAULT SLOT VALUE MUST BE DISCARDED', $html);
  }

  /**
   * A tree without deferred sources is unaffected.
   */
  public function testRegularTreeIsUnaffected(): void {
    $sdc_component = $this->loadComponent('sdc.canvas_test_sdc.props-slots');
    $tree = [
      [
        'uuid' => self::CHILD_UUID,
        'component_id' => $sdc_component->id(),
        'component_version' => $sdc_component->getActiveVersion(),
        'inputs' => [
          'heading' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'REGULAR RENDERING STILL WORKS',
            'expression' => 'ℹ︎string␟value',
          ],
        ],
      ],
    ];
    $vehicle = Pattern::create([
      'id' => 'regular_test',
      'label' => 'Regular test',
      'component_tree' => $tree,
    ]);
    $build = $vehicle->getComponentTree()->toRenderable($vehicle, FALSE);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    $this->assertStringContainsString('REGULAR RENDERING STILL WORKS', $html);
  }

  /**
   * Loads a Component config entity, asserting it exists.
   */
  private function loadComponent(string $id): Component {
    $component = Component::load($id);
    $this->assertInstanceOf(Component::class, $component);
    return $component;
  }

}
