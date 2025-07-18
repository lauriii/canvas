<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::patch()
 * @group experience_builder
 * @group #slow
 */
final class ApiLayoutControllerPatchTest extends ApiLayoutControllerTestBase {

  use XBFieldTrait;
  use AutoSaveRequestTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], [
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
    ]);
  }

  public function testEntityAccessRequired(): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
    ]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'edit any article content' permission is required.");

    $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: json_encode([
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [],
          'id' => 'content',
        ],
      ],
    ] + $this->getPatchContentsDefaults([Node::load(1)]), JSON_THROW_ON_ERROR)));
  }

  /**
   * @param class-string<\Throwable> $exception
   * @dataProvider providerInvalid
   */
  public function testInvalid(string $message, string $exception, array $content): void {
    $this->expectException($exception);
    $this->expectExceptionMessage($message);
    if (isset($content['autoSaves'])) {
      unset($content['autoSaves']);
      $content += $this->getClientAutoSaves([Node::load(1)]);
    }
    $this->parentRequest(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_NO_OPENAPI_VALIDATION' => 'turned off because we want to validate the prod response here',
    ], content: \json_encode($content, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)));
  }

  public static function providerInvalid(): iterable {
    yield 'no component instance uuid' => [
      'Missing componentInstanceUuid',
      BadRequestHttpException::class,
      [],
    ];
    yield 'no component type' => [
      'Missing componentType',
      BadRequestHttpException::class,
      [
        'componentInstanceUuid' => 'e8c95423-4f22-4210-8707-08bade75ff22',
      ],
    ];
    yield 'no model' => [
      'Missing model',
      BadRequestHttpException::class,
      [
        'componentInstanceUuid' => 'e8c95423-4f22-4210-8707-08bade75ff22',
        'componentType' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: e8c95423-4f22-4210-8707-08bade75ff22',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => 'e8c95423-4f22-4210-8707-08bade75ff22',
        'componentType' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
        'model' => [],
        'autoSaves' => [],
        'clientInstanceId' => 'sample-client-id',
      ],
    ];
    yield 'No such component' => [
      'No such component: garry_sensible_jeans',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => XbTestSetup::UUID_STATIC_IMAGE,
        'componentType' => 'garry_sensible_jeans@jean_shorts',
        'model' => [],
        'autoSaves' => [],
        'clientInstanceId' => 'sample-client-id',
      ],
    ];
    yield 'No version provided' => [
      'Missing version for component sdc.xb_test_sdc.image',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => XbTestSetup::UUID_STATIC_IMAGE,
        'componentType' => 'sdc.xb_test_sdc.image',
        'model' => [],
        'autoSaves' => [],
        'clientInstanceId' => 'sample-client-id',
      ],
    ];
    yield 'Invalid version provided' => [
      'No such version hamster for component sdc.xb_test_sdc.image',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => XbTestSetup::UUID_STATIC_IMAGE,
        'componentType' => 'sdc.xb_test_sdc.image@hamster',
        'model' => [],
        'autoSaves' => [],
        'clientInstanceId' => 'sample-client-id',
      ],
    ];
  }

  /**
   * @dataProvider providerValid
   */
  public function test(bool $withAutoSave = FALSE, bool $withGlobal = FALSE): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
    ]);
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = [];
    if ($withGlobal) {
      $regions = PageRegion::createFromBlockLayout('stark');
      foreach ($regions as $region) {
        $region->save();
      }
    }

    // Setup additional nesting of components.
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    $tree = $node->get('field_xb_demo');
    \assert($tree instanceof ComponentTreeItemList);
    $static_image = $tree->getComponentTreeItemByUuid(XbTestSetup::UUID_STATIC_IMAGE);
    \assert($static_image instanceof ComponentTreeItem);
    $static_image->set('parent_uuid', XbTestSetup::UUID_ALL_SLOTS_EMPTY);
    $static_image->set('slot', 'content');
    // We need to make sure the delta order reflects that parents come before
    // children otherwise this will happen on POST and create an auto-save entry.
    $image_delta = $tree->getComponentTreeDeltaByUuid(XBTestSetup::UUID_STATIC_IMAGE);
    $parent_delta = $tree->getComponentTreeDeltaByUuid(XBTestSetup::UUID_ALL_SLOTS_EMPTY);
    \assert($image_delta !== NULL);
    \assert($parent_delta !== NULL);
    $values = $tree->getValue();
    $values = [
      ...\array_slice($values, 0, $image_delta),
      ...\array_slice($values, $image_delta + 1, $parent_delta - $image_delta),
      ...\array_slice($values, $image_delta, 1),
      ...\array_slice($values, $parent_delta + 1),
    ];
    $node->set('field_xb_demo', $values);

    $node->save();

    // Load the test data from the layout controller.
    $response = $this->parentRequest(Request::create('/xb/api/v0/layout/node/1'));
    $this->assertResponseAutoSaves($response, [Node::load(1)], $withGlobal);
    $content = $response->getContent();
    self::assertIsString($content);
    $data = $this->decodeResponse($response);
    // Check that the client only receives field data they have access to.
    // @see ApiLayoutController::filterFormValues()
    $this->assertSame([
      'changed',
      'field_hero[0][target_id]',
      'field_hero[0][alt]',
      'field_hero[0][width]',
      'field_hero[0][height]',
      'field_hero[0][fids][0]',
      'field_hero[0][display]',
      'field_hero[0][description]',
      'field_hero[0][upload]',
      'media_image_field[media_library_selection]',
      'path[0][alias]',
      'path[0][source]',
      'path[0][langcode]',
      'title[0][value]',
      'langcode[0][value]',
      'revision',
    ], array_keys($data['entity_form_fields']));

    $model = $data['model'];

    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    if ($withAutoSave) {
      // Perform a POST first to trigger the auto-save manager being called.
      // This will not result in an auto-save entry because the content is the
      // same as the saved version.
      $response = $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
      $this->assertResponseAutoSaves($response, [Node::load(1)], $withGlobal);
      self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
      self::assertTrue($autoSave->getAutoSaveEntity($node)->isEmpty());
      foreach ($regions as $region) {
        self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
      }
    }

    // Update the image.
    $media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['name' => 'Hero image']);
    self::assertCount(1, $media);
    $media = reset($media);
    \assert($media instanceof MediaInterface);

    // Make sure the current value isn't the same media ID.
    self::assertNotEmpty($model[XbTestSetup::UUID_STATIC_IMAGE]['resolved']['image']);
    self::assertNotEquals($media->id(), $model[XbTestSetup::UUID_STATIC_IMAGE]['resolved']['image']);

    // Now patch the layout.
    $new_model = $model[XbTestSetup::UUID_STATIC_IMAGE];
    // Reference a new media entity.
    $new_model['source']['image']['value'] = $media->id();
    $updateImageClientData = [
      'model' => $new_model,
      'componentType' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
      'componentInstanceUuid' => XbTestSetup::UUID_STATIC_IMAGE,
    ] + $this->getPatchContentsDefaults([$node]);
    $response = $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode($updateImageClientData, JSON_THROW_ON_ERROR)));

    // The new model should contain the updated value.
    $data = self::decodeResponse($response);
    $this->assertResponseAutoSaves($response, [$node], $withGlobal);
    // The updated preview should reference the new image.
    $file = $media->get('field_media_image')->entity;
    \assert($file instanceof FileInterface);
    $fileUri = $file->getFileUri();
    \assert(is_string($fileUri));
    $image = $media->get('field_media_image')->get(0);
    \assert($image instanceof ImageItemOverride);
    $image_url = $image->get('src_with_alternate_widths')->getValue();
    self::assertEquals($image_url, $data['model'][XbTestSetup::UUID_STATIC_IMAGE]['resolved']['image']['src']);

    self::assertFalse($autoSave->getAutoSaveEntity($node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
    }

    // Check that each level is structured correctly.
    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $globalElements = [];
    if ($withGlobal) {
      $sidebar_first = $this->getRegion('sidebar_first');
      self::assertNotNull($sidebar_first);
      $globalElements = $this->getComponentInstances($sidebar_first);

      $highlighted = $this->getRegion('highlighted');
      self::assertNotNull($highlighted);
      $highlightedElements = $this->getComponentInstances($highlighted);
      $globalElements = [...$globalElements, ...$highlightedElements];
    }
    $contentElements = $this->getComponentInstances($content);
    self::assertCount($withGlobal ? 10 : 8, \array_merge($contentElements, $globalElements));
    if ($withGlobal) {
      self::assertSame(\array_keys($model), \array_merge($contentElements, $globalElements));
    }

    // There should be two images, one should reference the media item direct
    // (static-image-udf7d) and one should reference the thumbnail style
    // (static-image-static-imageStyle-something7d) because it uses an adapter.
    // @see \Drupal\experience_builder\Plugin\Adapter\ImageAndStyleAdapter
    $images = (new Crawler($data['html']))->filter('img')->extract(['src']);
    $thumbnail = ImageStyle::load('thumbnail');
    \assert($thumbnail instanceof ImageStyleInterface);
    self::assertCount(2, $images);
    self::assertEquals([
      $image_url,
      $thumbnail->buildUrl($fileUri),
    ], $images);

    unset($updateImageClientData['clientInstanceId']);
    $updateImageClientData += $this->getPatchContentsDefaults([$node]);
    $this->assertRequestAutoSaveConflict(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode($updateImageClientData, JSON_THROW_ON_ERROR)));

    if ($withGlobal) {
      $new_label = $this->randomMachineName();
      // Patch a global component.
      $globalComponentUuid = reset($globalElements);
      $updateRegionClientData = [
        'model' => [
          'resolved' => [
            'label' => $new_label,
            'label_display' => '',
          ],
        ],
        'componentType' => 'block.system_messages_block@b92f802cf68eb83e',
        'componentInstanceUuid' => $globalComponentUuid,
      ] + $this->getPatchContentsDefaults([$node]);
      $response = $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode($updateRegionClientData, JSON_THROW_ON_ERROR)));

      // The new model should contain the updated value.
      $data = self::decodeResponse($response);
      self::assertEquals($new_label, $data['model'][$globalComponentUuid]['resolved']['label']);

      self::assertFalse($autoSave->getAutoSaveEntity($node)->isEmpty());
      $sidebarFirstRegion = NULL;
      foreach ($regions as $region) {
        // The updated component is in sidebar_first and so auto-save should not
        // be empty.
        self::assertEquals($region->get('region') !== 'sidebar_first', $autoSave->getAutoSaveEntity($region)->isEmpty());
        if ($region->get('region') === 'sidebar_first') {
          $sidebarFirstRegion = $region;
          $this->assertResponseAutoSaves($response, [$node], $withGlobal);
        }
      }
      $this->assertNotNull($sidebarFirstRegion);

      // Trying to post the same data again should throw a conflict exception
      // because it does not contain the auto-save hash of the region.
      $updateRegionClientData['clientInstanceId'] .= '-new-client';
      $this->assertRequestAutoSaveConflict(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode($updateRegionClientData, JSON_THROW_ON_ERROR)));

      unset($updateRegionClientData['autoSaves']);
      $updateRegionClientData['clientInstanceId'] .= '-new-client2';
      $updateRegionClientData += $this->getClientAutoSaves([$node], $withGlobal);
      $response = $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode($updateRegionClientData, JSON_THROW_ON_ERROR)));
      $this->assertSame(200, $response->getStatusCode());
    }
  }

  public static function providerValid(): iterable {
    yield 'fresh state, no global' => [];
    yield 'fresh state, global' => [FALSE, TRUE];
    yield 'existing auto-save, no global' => [TRUE, FALSE];
    yield 'existing auto-save, global' => [TRUE, TRUE];
  }

  public function testWithoutPageRegionPermission(): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      'edit any article content',
    ]);

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }
    // Load the test data from the layout controller.
    $this->request(Request::create('/xb/api/v0/layout/node/1'))->getContent();

    // Check that content region exist and is wrapped.
    $contentRegion = $this->getRegion('content');
    $this->assertNotNull($contentRegion);
    // But not the highlighted region, as we don't have access to it.
    $highlighted = $this->getRegion('highlighted');
    self::assertNull($highlighted);

    $new_label = $this->randomMachineName();
    // Patch a component instance in a ("global") region.
    // We need to use the APIs to get the UUID of a valid component instance in a region.
    $component_tree_values = $regions['stark.highlighted']->getComponentTree()->getValue();
    $globalComponentUuids = \array_column($component_tree_values, 'uuid');
    // There is only one block, the title, in the highlighted region.
    $this->assertCount(1, $globalComponentUuids);
    $globalComponentUuid = $globalComponentUuids[0];

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Access denied for region highlighted');

    $this->request(Request::create('/xb/api/v0/layout/node/1', method: 'PATCH', content: \json_encode([
      'model' => [
        'resolved' => [
          'label' => $new_label,
          'label_display' => '',
        ],
      ],
      'componentType' => 'block.system_messages_block@b92f802cf68eb83e',
      'componentInstanceUuid' => $globalComponentUuid,
    ] + $this->getPatchContentsDefaults([Node::load(1)], FALSE), JSON_THROW_ON_ERROR)));
  }

}
