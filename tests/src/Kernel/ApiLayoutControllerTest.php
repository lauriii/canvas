<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiLayoutController
 * @group experience_builder
 */
class ApiLayoutControllerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
  }

  public function test(): void {
    $node = Node::load(1);
    /** @var \Drupal\experience_builder\Controller\ApiLayoutController $controller */
    $controller = \Drupal::classResolver(ApiLayoutController::class);
    assert($node instanceof FieldableEntityInterface);
    $response = $controller($node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('layout', $json);

    $layout = $json['layout'];
    $this->assertSame('region', $layout['nodeType']);
    $this->assertSame('content', $layout['name']);
    $this->assertArrayHasKey('components', $layout);

    // @todo recurse through the tree
    foreach ($layout['components'] as $child) {
      $this->assertArrayHasKey('nodeType', $child);
      $this->assertArrayHasKey('uuid', $child);
      $this->assertArrayHasKey('type', $child);
      $this->assertArrayHasKey('slots', $child);

      // @todo check non SDC components
      $this->assertSame('component', $child['nodeType']);
      $this->assertStringStartsWith('sdc.', $child['type']);
    }
  }

}
