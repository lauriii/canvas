<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiPreviewController
 * @group experience_builder
 */
final class ApiPreviewControllerTest extends KernelTestBase {

  use RequestTrait {
    request as parentRequest;
  }
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], ['access administration pages']);
  }

  public function testEmpty(): void {
    $this->request(Request::create('/api/preview/node/1', content: json_encode([
      'layout' => [
        'nodeType' => 'region',
        'name' => 'content',
        'components' => [],
      ],
      'model' => [],
    ], JSON_THROW_ON_ERROR)));

    // Check that the root level is structured correctly.
    $root = $this->cssSelect('main div.xb--sortable-list[data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertCount(0, $root[0]);
  }

  public function test(): void {
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $model = json_decode($content, TRUE)['model'];
    $this->request(Request::create('/api/preview/node/1', content: $content));

    // Check that each level is structured correctly.
    $root = $this->cssSelect('main div.xb--sortable-list[data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    $uuids = $this->assertStructure($root[0]->children());
    $this->assertSame(array_keys($model), $uuids);
  }

  /**
   * Unwrap the JSON response so we can perform assertions on it.
   */
  protected function request(Request $request): Response {
    $response = $this->parentRequest($request);
    $content = $response->getContent();
    $this->assertIsString($content);
    $this->setRawContent(json_decode($content, TRUE)['html']);
    return $response;
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
