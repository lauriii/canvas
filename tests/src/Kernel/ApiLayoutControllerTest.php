<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Controller\ApiLayoutController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
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

    $layout = $json['layout'][0];
    $this->assertSame('region', $layout['nodeType']);
    $this->assertSame('Content', $layout['name']);
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
