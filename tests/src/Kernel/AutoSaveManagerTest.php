<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;

/**
 * @coversDefaultClass \Drupal\experience_builder\AutoSave\AutoSaveManager
 * @group experience_builder.
 */
class AutoSaveManagerTest extends KernelTestBase {

  use TestDataUtilitiesTrait;
  use XBFieldTrait;
  use ContribStrictConfigSchemaTestTrait;

  protected static $modules = ['xb_test_sdc'];

  private static function recursiveReverseSort(array $data): array {
    // If $data is associative array reverse it, but preserve the keys.
    if (!array_is_list($data)) {
      $data = array_reverse($data, TRUE);
    }
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = self::recursiveReverseSort($value);
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    (new XBTestSetup())->setup();
    $this->setUpImages();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('xb_page');
  }

  private function assertAutoSaveCreated(EntityInterface $entity, array $matching_client_data, array $updated_client_data): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    assert($autoSave instanceof AutoSaveManager);
    $autoSave->recordInitialClientSideRepresentation($entity, $matching_client_data);
    $autoSave->save($entity, $matching_client_data);
    self::assertTrue($autoSave->getAutoSaveData($entity)->isEmpty());
    // Reversing the order of the data should not trigger an autosave entry either.
    $autoSave->save($entity, self::recursiveReverseSort($matching_client_data));
    self::assertTrue($autoSave->getAutoSaveData($entity)->isEmpty());

    $autoSave->save($entity, $updated_client_data);
    self::assertFalse($autoSave->getAutoSaveData($entity)->isEmpty());
    $autoSaveKey = AutoSaveManager::getAutoSaveKey($entity);
    $autoSaveEntry = $autoSave->getAllAutoSaveList()[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashInitial = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashInitial);

    // Reversing the order of the data should result in the exact same hash.
    $autoSave->save($entity, self::recursiveReverseSort($updated_client_data));
    self::assertFalse($autoSave->getAutoSaveData($entity)->isEmpty());
    $autoSaveEntry = $autoSave->getAllAutoSaveList()[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashReversedData = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashReversedData);
    self::assertSame($hashInitial, $hashReversedData);

    // Resaving the initial state should delete the autosave entry.
    $autoSave->save($entity, $matching_client_data);
    self::assertTrue($autoSave->getAutoSaveData($entity)->isEmpty());
  }

  public function testXbPage(): void {
    $xb_page = Page::create([
      'title' => '5 amazing uses for old toothbrushes',
      'components' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'inputs' => '{}',
      ],
    ]);
    self::assertCount(0, iterator_to_array($xb_page->validate()));
    self::assertSame(SAVED_NEW, $xb_page->save());

    $matching_client_data = [
      'layout' => [
        [
          'nodeType' => 'region',
          'id' => 'content',
          'name' => 'Content',
          'components' => [],
        ],
      ],
      'model' => [],
      'entity_form_fields' => [
        'this' => 'does not really matter here',
      ],
    ];
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($xb_page, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component triggers an autosave entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      'type' => 'sdc.experience_builder.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($xb_page, $matching_client_data, $new_component_client_data);
  }

  public function testPageRegion(): void {
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => $label,
        'expression' => 'ℹ︎string␟value',
      ];
    };

    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            ['uuid' => 'uuid-in-root', 'component' => 'sdc.xb_test_sdc.props-no-slots'],
          ],
        ]),
        'inputs' => self::encodeXBData([
          'uuid-in-root' => [
            'heading' => $generate_static_prop_source('world'),
          ],
        ]),
      ],
    ]);
    $this->assertSame(SAVED_NEW, $page_region->save());
    $page_region_matching_client_data = [
      'layout' => [
        [
          'nodeType' => 'component',
          'uuid' => 'uuid-in-root',
          'type' => 'sdc.xb_test_sdc.props-no-slots',
          'slots' => [],
        ],
      ],
      'model' => [
        'uuid-in-root' => [
          'resolved' => ['heading' => 'This is a random heading.'],
        ],
      ],
    ];
    $non_matching_region_client_data = $page_region_matching_client_data;
    $non_matching_region_client_data['model'][self::TEST_HEADING_UUID]['resolved']['text'] = 'This is a different heading.';
    $this->assertAutoSaveCreated($page_region, $page_region_matching_client_data, $non_matching_region_client_data);
  }

  public function testJsComponent(): void {
    $js_component = JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
    ]);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $js_component_matching_client_data = $js_component->normalizeForClientSide()->values;
    $non_matching_js_component_client_data = $js_component_matching_client_data;
    $non_matching_js_component_client_data['props']['text']['examples'][] = 'Press, or don\'t. Whatever.';
    $this->assertAutoSaveCreated($js_component, $js_component_matching_client_data, $non_matching_js_component_client_data);
  }

  public function testAssetLibrary(): void {
    $asset_library = AssetLibrary::load('global');
    assert($asset_library instanceof AssetLibrary);
    $asset_library_matching_client_data = $asset_library->normalizeForClientSide()->values;
    $non_matching_asset_library_client_data = $asset_library_matching_client_data;
    $non_matching_asset_library_client_data['label'] = 'Slightly less boring label';
    $this->assertAutoSaveCreated($asset_library, $asset_library_matching_client_data, $non_matching_asset_library_client_data);
  }

  public function testNode(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'status' => FALSE,
      'field_hero' => $this->referencedImage,
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'inputs' => '{}',
      ],
    ]);
    self::assertCount(0, iterator_to_array($node->validate()));
    $this->assertSame(SAVED_NEW, $node->save());

    $matching_client_data = [
      'layout' => [
        [
          'nodeType' => 'region',
          'id' => 'content',
          'name' => 'Content',
          'components' => [],
        ],
      ],
      'model' => [],
      'entity_form_fields' => [
        'title[0][value]' => '5 amazing uses for old toothbrushes',
      ],
    ];
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component to the node via the client also triggers an autosave entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      'type' => 'sdc.experience_builder.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_component_client_data);
  }

}
