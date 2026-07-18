<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Tests the partial render endpoint and the frozen/persist-only preview modes.
 *
 * @legacy-covers \Drupal\canvas\Controller\ApiLayoutController::render
 * @see docs/adr/0017-preview-partial-rendering-frozen-regions.md
 */
#[Group('canvas')]
#[Group('#slow')]
#[RunTestsInSeparateProcesses]
final class ApiLayoutControllerRenderTest extends ApiLayoutControllerTestBase {

  use CanvasFieldTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block', 'user']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();
    (new CanvasTestSetup())->setup(TRUE);
    $this->setUpCurrentUser([], [
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
    ]);
  }

  /**
   * The render endpoint is a pure function: no auto-save writes, ever.
   */
  public function testRenderEndpointIsPure(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof Node);
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());

    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_STATIC_CARD1],
      'token' => 'token-42',
    ], JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response);

    // Token is echoed opaquely.
    self::assertSame('token-42', $data['token']);
    // Only the requested instance is rendered, wrapped in its markers.
    self::assertSame([CanvasTestSetup::UUID_STATIC_CARD1], \array_keys($data['html']));
    $html = $data['html'][CanvasTestSetup::UUID_STATIC_CARD1];
    self::assertStringContainsString('<!-- canvas-start-' . CanvasTestSetup::UUID_STATIC_CARD1 . ' -->', $html);
    self::assertStringContainsString('hello, world!', $html);
    self::assertStringNotContainsString(CanvasTestSetup::UUID_STATIC_IMAGE, $html);
    // The evaluated client model for the rendered instance is included.
    self::assertArrayHasKey(CanvasTestSetup::UUID_STATIC_CARD1, $data['model']);

    // No auto-save entry was created: rendering left no trace.
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
  }

  /**
   * A client model overlay affects output but never the stored draft.
   */
  public function testRenderModelOverlayLeavesDraftUntouched(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof Node);

    // Get the current client-side model for the component.
    $layout_response = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'));
    $layout_data = self::decodeResponse($layout_response);
    $card_model = $layout_data['model'][CanvasTestSetup::UUID_STATIC_CARD1];
    $card_model['source']['heading']['value'] = 'Overlaid heading';
    $card_model['resolved']['heading'] = 'Overlaid heading';

    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_STATIC_CARD1],
      'model' => [CanvasTestSetup::UUID_STATIC_CARD1 => $card_model],
    ], JSON_THROW_ON_ERROR)));
    $data = self::decodeResponse($response);
    self::assertStringContainsString('Overlaid heading', $data['html'][CanvasTestSetup::UUID_STATIC_CARD1]);

    // The overlay was applied to a dangling copy only: no auto-save entry,
    // and a fresh render without the overlay shows the stored draft state.
    self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_STATIC_CARD1],
    ], JSON_THROW_ON_ERROR)));
    $data = self::decodeResponse($response);
    self::assertStringContainsString('hello, world!', $data['html'][CanvasTestSetup::UUID_STATIC_CARD1]);
    self::assertStringNotContainsString('Overlaid heading', $data['html'][CanvasTestSetup::UUID_STATIC_CARD1]);
  }

  /**
   * A slot-bearing component renders as a subtree including its children.
   */
  public function testRenderSubtreeIncludesSlotChildren(): void {
    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_TWO_COLUMN_UUID],
    ], JSON_THROW_ON_ERROR)));
    $data = self::decodeResponse($response);
    $html = $data['html'][CanvasTestSetup::UUID_TWO_COLUMN_UUID];
    self::assertStringContainsString('<!-- canvas-start-' . CanvasTestSetup::UUID_TWO_COLUMN_UUID . ' -->', $html);
    // Children in slots are nested inside the subtree markup.
    self::assertStringContainsString('<!-- canvas-start-' . CanvasTestSetup::UUID_STATIC_CARD1 . ' -->', $html);
    // The subtree's model includes the children.
    self::assertArrayHasKey(CanvasTestSetup::UUID_STATIC_CARD1, $data['model']);
  }

  /**
   * Asset deltas follow the ajaxPageState pattern.
   */
  public function testRenderAssetDelta(): void {
    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_STATIC_CARD1],
      'libraries' => [],
    ], JSON_THROW_ON_ERROR)));
    $data = self::decodeResponse($response);
    self::assertNotEmpty($data['assets']['libraries']);

    // Echoing the returned library list back yields an empty delta.
    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1/render', method: 'POST', content: \json_encode([
      'uuids' => [CanvasTestSetup::UUID_STATIC_CARD1],
      'libraries' => $data['assets']['libraries'],
    ], JSON_THROW_ON_ERROR)));
    $delta = self::decodeResponse($response);
    self::assertSame([], $delta['assets']['css']);
    self::assertSame([], $delta['assets']['js']);
    self::assertSame($data['assets']['libraries'], $delta['assets']['libraries']);
  }

  /**
   * `render: false` persists without rendering any preview HTML.
   */
  public function testPersistOnlyPost(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof Node);

    $content = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'))->getContent();
    self::assertIsString($content);
    $json = \json_decode($this->filterLayoutForPost($content), TRUE);
    // Change the title so an auto-save entry is actually created.
    $json['entity_form_fields']['title[0][value]'] = 'Persist-only title';
    $json['render'] = FALSE;

    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertArrayNotHasKey('html', $data);
    self::assertArrayHasKey('autoSaves', $data);
    self::assertFalse($autoSave->getAutoSaveEntity($node)->isEmpty());
  }

  /**
   * Frozen regions: no validation, no writes, no region rendering.
   */
  public function testFrozenRegions(): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $node = Node::load(1);
    \assert($node instanceof Node);
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }

    $content = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'))->getContent();
    self::assertIsString($content);
    $json = \json_decode($this->filterLayoutForPost($content), TRUE);
    $json['entity_form_fields']['title[0][value]'] = 'Frozen regions title';
    $json['frozen'] = 'regions';
    // Validation exemption: the frozen tree's hashes may be stale or absent.
    foreach (\array_keys($json['autoSaves']) as $key) {
      if (\str_contains($key, PageRegion::ENTITY_TYPE_ID)) {
        $json['autoSaves'][$key]['hash'] = 'stale-and-wrong';
      }
    }

    $response = $this->request(Request::create('/canvas/api/v0/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    // The entity was written, no region was.
    self::assertFalse($autoSave->getAutoSaveEntity($node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
    }
  }

  /**
   * The PATCH guard refuses to modify a component in a frozen tree.
   */
  public function testFrozenTreePatchGuard(): void {
    $layout_response = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'));
    $layout_data = self::decodeResponse($layout_response);
    $card_model = $layout_data['model'][CanvasTestSetup::UUID_STATIC_CARD1];

    // The component lives in the content tree, so declaring the content tree
    // frozen while patching it must be refused.
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('frozen content tree');
    $this->request(Request::create('/canvas/api/v0/layout/node/1', method: 'PATCH', content: \json_encode([
      'componentInstanceUuid' => CanvasTestSetup::UUID_STATIC_CARD1,
      'componentType' => $this->getComponentTypeWithVersion($layout_data, CanvasTestSetup::UUID_STATIC_CARD1),
      'model' => $card_model,
      'frozen' => 'content',
    ] + $this->getPatchContentsDefaults([Node::load(1)]), JSON_THROW_ON_ERROR)));
  }

  /**
   * Frozen requests reject unknown values.
   *
   * The OpenAPI schema (a `frozen` enum) rejects this before the controller's
   * own guard; both exist so production (where request validation is off)
   * fails just as loudly.
   */
  public function testInvalidFrozenValue(): void {
    $content = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'))->getContent();
    self::assertIsString($content);
    $json = \json_decode($this->filterLayoutForPost($content), TRUE);
    $json['frozen'] = 'everything';
    $this->expectException(InvalidBody::class);
    $this->request(Request::create('/canvas/api/v0/layout/node/1', method: 'POST', content: \json_encode($json, JSON_THROW_ON_ERROR)));
  }

  /**
   * Finds a component's `type@version` in a GET layout response.
   */
  private function getComponentTypeWithVersion(array $layout_data, string $uuid): string {
    $find = function (array $components) use (&$find, $uuid): ?string {
      foreach ($components as $component) {
        if (($component['uuid'] ?? NULL) === $uuid) {
          return $component['type'];
        }
        foreach ($component['slots'] ?? [] as $slot) {
          $result = $find($slot['components'] ?? []);
          if ($result !== NULL) {
            return $result;
          }
        }
      }
      return NULL;
    };
    foreach ($layout_data['layout'] as $region) {
      $result = $find($region['components'] ?? []);
      if ($result !== NULL) {
        return $result;
      }
    }
    throw new \LogicException("Component $uuid not found in layout.");
  }

}
