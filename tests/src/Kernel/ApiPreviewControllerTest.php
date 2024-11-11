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

  public function test(): void {
    // Load the test data from the layout controller.
    $node = Node::load(1);
    $controller = new ApiLayoutController();
    assert($node instanceof FieldableEntityInterface);
    $response = $controller($node);
    $this->assertInstanceOf(JsonResponse::class, $response);

    // Pass it straight back to the preview controller.
    $controller = \Drupal::service(ApiPreviewController::class);
    $response = $controller(new Request(content: (string) $response->getContent()), $node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('html', $json);
    $this->setRawContent($json['html']);

    // Check that the first level is structured correctly.
    $root = $this->cssSelect('main div.xb--sortable-list[data-xb-uuid="root"]');
    $this->assertNotEmpty($root);
    foreach ($root[0]->div as $child) {
      $this->assertEquals('xb--sortable-item', $child->attributes()->class);
      $this->assertNotEmpty((string) $child->attributes()->{'data-xb-uuid'});
      $this->assertNotEquals(ComponentTreeStructure::ROOT_UUID, $child->attributes()->{'data-xb-uuid'});
      $this->assertNotEmpty((string) $child->attributes()->{'data-xb-component-id'});
    }
  }

}
