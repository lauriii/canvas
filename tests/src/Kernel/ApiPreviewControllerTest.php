<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\experience_builder\Controller\ApiPreviewController;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiPreviewController
 * @group experience_builder
 */
class ApiPreviewControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
  }

  public function testEmpty(): void {
    $node = Node::load(1);
    assert($node instanceof FieldableEntityInterface);
    $content = [
      'layout' => [
        'children' => [],
      ],
      'model' => [],
    ];

    $controller = \Drupal::service(ApiPreviewController::class);
    $response = $controller(new Request(content: (string) json_encode($content)), $node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);

    // Check that the root level is structured correctly.
    $root = $this->cssSelect('main div.xb--sortable-list[data-xb-uuid="root"]');
    $this->assertNotEmpty($root);
    $this->assertCount(0, $root[0]);
  }

  public function test(): void {
    // Load the test data from the layout controller.
    $node = Node::load(1);
    $controller = new ApiLayoutController();
    assert($node instanceof FieldableEntityInterface);
    $response = $controller($node);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $model = json_decode($response->getContent() ?: '', TRUE)['model'];

    // Pass it straight back to the preview controller.
    $controller = \Drupal::service(ApiPreviewController::class);
    $response = $controller(new Request(content: (string) $response->getContent()), $node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);

    // Check that each level is structured correctly.
    $root = $this->cssSelect('main div.xb--sortable-list[data-xb-uuid="root"]');
    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    $uuids = $this->assertStructure($root[0]->children());
    $this->assertSame(array_keys($model), $uuids);
  }

  private function assertStructure(\SimpleXMLElement $items): array {
    $uuids = [];
    foreach ($items as $item) {
      switch ((string) $item->attributes()->class) {
        case 'xb--sortable-item':
          $this->assertNotEmpty((string) $item->attributes()->{'data-xb-uuid'});
          $uuids[] = (string) $item->attributes()->{'data-xb-uuid'};
          $this->assertNotEquals(ComponentTreeStructure::ROOT_UUID, $item->attributes()->{'data-xb-uuid'});
          $this->assertNotEmpty((string) $item->attributes()->{'data-xb-component-id'});
          break;

        case 'xb--sortable-list':
          $this->assertNotEmpty((string) $item->attributes()->{'data-xb-uuid'});
          $this->assertNotEquals(ComponentTreeStructure::ROOT_UUID, $item->attributes()->{'data-xb-uuid'});
          $this->assertSame('slot', (string) $item->attributes()->{'data-xb-component-id'});
          break;
      }

      if ($item->count()) {
        $uuids = array_merge($uuids, $this->assertStructure($item->children()));
      }
    }

    return $uuids;
  }

}
