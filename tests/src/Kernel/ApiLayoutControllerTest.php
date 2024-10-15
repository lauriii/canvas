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
    $controller = new ApiLayoutController();
    assert($node instanceof FieldableEntityInterface);
    $response = $controller($node);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $json = json_decode($response->getContent() ?: '', TRUE);
    $this->assertArrayHasKey('layout', $json);

    $layout = $json['layout'];
    $this->assertSame('root', $layout['uuid']);
    $this->assertSame('root', $layout['nodeType']);
    $this->assertSame('root', $layout['name']);
    $this->assertArrayHasKey('children', $layout);

    // @todo recurse through the tree
    foreach ($layout['children'] as $child) {
      $this->assertArrayHasKey('uuid', $child);
      $this->assertArrayHasKey('nodeType', $child);
      $this->assertArrayHasKey('type', $child);
      $this->assertArrayHasKey('children', $child);

      // @todo check non SDC components
      $this->assertSame('component', $child['nodeType']);
      $this->assertStringStartsWith('sdc.', $child['type']);
    }
  }

}
