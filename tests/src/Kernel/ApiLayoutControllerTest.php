<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiLayoutController
 * @group experience_builder
 */
class ApiLayoutControllerTest extends KernelTestBase {

  /**
   * Allows format=uri to be stored using URI field type.
   */
  protected static $modules = ['xb_test_storage_prop_shape_alter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
  }

  public function test(): void {
    // By default, there is only the Content region.
    $this->assertRegions(1);

    // Enable Stark and set it as the default theme.
    $theme = 'stark';
    $this->container->get('theme_installer')->install([$theme]);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', $theme)->save();
    $this->container->get('theme.manager')->resetActiveTheme();
    $template = PageTemplate::createFromBlockLayout($theme);

    // Check that all the regions are present.
    $template->enable()->save();
    $this->assertRegions(12);

    // Disable the template and check that only the Content region is present.
    $template->disable()->save();
    $this->assertRegions(1);

    // Store a draft in the autosave manager and confirm that is returned.
    $template->enable()->save();
    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $templateData = [
      'layout' => [
        [
          "components" => [
            [
              "nodeType" => "component",
              "slots" => [],
              "type" => "block.page_title_block",
              "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
            ],
          ],
          "name" => "Highlighted",
          "nodeType" => "region",
          "id" => "highlighted",
        ],
      ],
      'model' => [
        "c3f3c22c-c22e-4bb6-ad16-635f069148e4" => [
          "label" => "Page title",
          "label_display" => "0",
          "provider" => "core",
        ],
      ],
    ];
    $autoSave->save($template, $templateData);
    /** @var \Drupal\experience_builder\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    $node1 = Node::load(1);
    \assert($node1 instanceof NodeInterface);

    // Draft of highlighted region in global template should be returned even if
    // there is no autosave data for the node.
    $response = $controller($node1);
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
      ],
    ], reset($highlightedRegion)['components']);

    // Now let's add an autosave entry for the node.
    $sampleData = \file_get_contents(\dirname(__DIR__, 3) . '/ui/tests/fixtures/layout-default.json');
    self::assertNotFalse($sampleData);
    $data = \json_decode($sampleData, TRUE);
    // Update the page title.
    $new_title = $this->getRandomGenerator()->sentences(10);
    $data['entity_form_fields']['title[0][value]'] = $new_title;
    $node1 = Node::load(1);
    \assert($node1 instanceof NodeInterface);
    $autoSave->save($node1, $data);
    $response = $controller($node1);

    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertArrayHasKey('model', $json);
    self::assertArrayHasKey('c3f3c22c-c22e-4bb6-ad16-635f069148e4', $json['model']);
    self::assertEquals('Page title', $json['model']['c3f3c22c-c22e-4bb6-ad16-635f069148e4']['label']);
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block",
        "uuid" => "c3f3c22c-c22e-4bb6-ad16-635f069148e4",
      ],
    ], reset($highlightedRegion)['components']);
    self::assertEquals($new_title, $json['entity_form_fields']['title[0][value]']);

    // Now let's remove the draft of the page template but retain that of the
    // node.
    $autoSave->delete($template);
    // We should still see the global regions.
    $response = $controller($node1);
    self::assertInstanceOf(JsonResponse::class, $response);
    $json = \json_decode($response->getContent() ?: '', TRUE);
    self::assertArrayHasKey('layout', $json);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    // @see \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup()
    self::assertEquals([
      [
        "nodeType" => "component",
        "slots" => [],
        "type" => "block.page_title_block",
      ],
    ],
      // Filter out the UUID as that is added randomly by creating the block
      // in the setup class.
      \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), \current($highlightedRegion)['components']));
  }

  protected function assertRegions(int $count): void {
    $node = Node::load(1);
    /** @var \Drupal\experience_builder\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    assert($node instanceof FieldableEntityInterface);
    $response = $controller($node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('layout', $json);
    $this->assertCount($count, $json['layout']);

    foreach ($json['layout'] as $region) {
      $this->assertArrayHasKey('nodeType', $region);
      $this->assertSame('region', $region['nodeType']);
      $this->assertArrayHasKey('id', $region);
      $this->assertArrayHasKey('name', $region);
      $this->assertArrayHasKey('components', $region);

      if ($region['id'] === 'highlighted') {
        // @see \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup()
        $this->assertEquals([
          [
            "nodeType" => "component",
            "slots" => [],
            "type" => "block.page_title_block",
          ],
        ],
          // Filter out the UUID as that is added randomly by creating the block
          // in the setup class.
          \array_map(static fn(array $component) => \array_diff_key($component, \array_flip(['uuid'])), $region['components']));
        continue;
      }
      if ($region['id'] !== 'content') {
        $this->assertEmpty($region['components']);
        continue;
      }
      $this->assertSame('Content', $region['name']);
      $this->assertSame([
        [
          'nodeType' => 'component',
          'uuid' => 'two-column-uuid',
          'type' => 'sdc.experience_builder.two_column',
          'slots' => [
            [
              'nodeType' => 'slot',
              'id' => 'two-column-uuid/column_one',
              'name' => 'column_one',
              'components' => [
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-image-udf7d',
                  'type' => 'sdc.experience_builder.image',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card1ab',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
              ],
            ],
            [
              'nodeType' => 'slot',
              'id' => 'two-column-uuid/column_two',
              'name' => 'column_two',
              'components' => [
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card2df',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-static-card3rr',
                  'type' => 'sdc.experience_builder.my-hero',
                  'slots' => [],
                ],
                [
                  'nodeType' => 'component',
                  'uuid' => 'static-image-static-imageStyle-something7d',
                  'type' => 'sdc.experience_builder.image',
                  'slots' => [],
                ],
              ],
            ],
          ],
        ],
      ], $region['components']);
    }

    $this->assertArrayHasKey('entity_form_fields', $json);
    $this->assertSame($node->label(), $json['entity_form_fields']['title[0][value]']);

    self::assertEquals([
      'resolved' => [
        'heading' => $node->label(),
        'cta1href' => 'https://drupal.org',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',

        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
    ], $json['model']['static-static-card2df']);
  }

  public function testFieldException(): void {
    $page_type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $page_type->save();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test',
    ]);
    $node->save();
    /** @var \Drupal\experience_builder\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('For now XB only works if the entity is an xb_page or an article node! Other entity types and bundles must be tested before they are supported, to help see https://drupal.org/i/3493675.');
    $controller($node);
  }

}
