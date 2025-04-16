<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::patch()
 * @group experience_builder
 */
final class ApiLayoutControllerPatchTest extends ApiLayoutControllerTestBase {

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
      'access administration pages',
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
    ]);
  }

  /**
   * @param class-string<\Throwable> $exception
   * @dataProvider providerInvalid
   */
  public function testInvalid(string $message, string $exception, array $content): void {
    $this->expectException($exception);
    $this->expectExceptionMessage($message);
    $this->parentRequest(Request::create('/xb/api/layout/node/1', method: 'PATCH', server: [
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
        'componentType' => 'sdc.experience_builder.image',
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: e8c95423-4f22-4210-8707-08bade75ff22',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => 'e8c95423-4f22-4210-8707-08bade75ff22',
        'componentType' => 'sdc.experience_builder.image',
        'model' => [],
      ],
    ];
    yield 'No such component' => [
      'No such component: garry_sensible_jeans',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => 'static-image-udf7d',
        'componentType' => 'garry_sensible_jeans',
        'model' => [],
      ],
    ];
  }

  /**
   * @dataProvider providerValid
   */
  public function test(bool $withAutoSave = FALSE, bool $withGlobal = FALSE): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = [];
    if ($withGlobal) {
      $regions = PageRegion::createFromBlockLayout('stark');
      foreach ($regions as $region) {
        $region->save();
      }
    }
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/xb/api/layout/node/1'))->getContent();
    self::assertIsString($content);
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
      'revision_log[0][value]',
    ], array_keys(json_decode($content, TRUE)['entity_form_fields']));

    $model = json_decode($content, TRUE)['model'];

    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    if ($withAutoSave) {
      // Perform a POST first to trigger the auto-save manager being called.
      // This will not result in an auto-save entry because the content is the
      // same as the saved version.
      $response = $this->request(Request::create('/xb/api/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
      self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
      self::assertTrue($autoSave->getAutoSaveData($node)->isEmpty());
      foreach ($regions as $region) {
        self::assertTrue($autoSave->getAutoSaveData($region)->isEmpty());
      }
    }

    // Update the image.
    $media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['name' => 'Hero image']);
    self::assertCount(1, $media);
    $media = reset($media);
    \assert($media instanceof MediaInterface);

    // Make sure the current value isn't the same media ID.
    self::assertNotEquals($media->id(), $model['static-image-udf7d']['resolved']['image']);

    // Now patch the layout.
    $new_model = $model['static-image-udf7d'];
    // Reference a new media entity.
    $new_model['source']['image']['value'] = $media->id();
    $response = $this->request(Request::create('/xb/api/layout/node/1', method: 'PATCH', content: \json_encode([
      'model' => $new_model,
      'componentType' => 'sdc.experience_builder.image',
      'componentInstanceUuid' => 'static-image-udf7d',
    ], JSON_THROW_ON_ERROR)));

    // The new model should contain the updated value.
    $data = self::decodeResponse($response);
    // The updated preview should reference the new image.
    $file = $media->get('field_media_image')->entity;
    \assert($file instanceof FileInterface);
    $fileUri = $file->getFileUri();
    \assert(is_string($fileUri));
    $image_url = $this->container->get(FileUrlGeneratorInterface::class)->generateString($fileUri);
    self::assertEquals($image_url, $data['model']['static-image-udf7d']['resolved']['image']['src']);

    self::assertFalse($autoSave->getAutoSaveData($node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveData($region)->isEmpty());
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
    self::assertCount($withGlobal ? 8 : 6, \array_merge($contentElements, $globalElements));
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

    if ($withGlobal) {
      $new_label = $this->randomMachineName();
      // Patch a global component.
      $globalComponentUuid = reset($globalElements);
      $response = $this->request(Request::create('/xb/api/layout/node/1', method: 'PATCH', content: \json_encode([
        'model' => [
          'resolved' => [
            'label' => $new_label,
            'label_display' => '',
          ],
        ],
        'componentType' => 'block.system_messages_block',
        'componentInstanceUuid' => $globalComponentUuid,
      ], JSON_THROW_ON_ERROR)));

      // The new model should contain the updated value.
      $data = self::decodeResponse($response);
      self::assertEquals($new_label, $data['model'][$globalComponentUuid]['resolved']['label']);

      self::assertFalse($autoSave->getAutoSaveData($node)->isEmpty());
      foreach ($regions as $region) {
        // The updated component is in sidebar_first and so auto-save should not
        // be empty.
        self::assertEquals($region->get('region') !== 'sidebar_first', $autoSave->getAutoSaveData($region)->isEmpty());
      }
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
      'access administration pages',
      'administer url aliases',
    ]);

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }
    // Load the test data from the layout controller.
    $this->request(Request::create('/xb/api/layout/node/1'))->getContent();

    // Check that content region exist and is wrapped.
    $contentRegion = $this->getRegion('content');
    $this->assertNotNull($contentRegion);
    // But not the highlighted region, as we don't have access to it.
    $highlighted = $this->getRegion('highlighted');
    self::assertNull($highlighted);

    $new_label = $this->randomMachineName();
    // Patch a component instance in a ("global") region.
    // We need to use the APIs to get the UUID of a valid component instance in a region.
    $component_tree_structure = $regions['stark.highlighted']->getComponentTree()->get('tree');
    $globalComponentUuids = $component_tree_structure->getComponentInstanceUuids();
    // There is only one block, the title, in the highlighted region.
    $this->assertCount(1, $globalComponentUuids);
    $globalComponentUuid = $globalComponentUuids[0];

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Access denied for region highlighted');

    $this->request(Request::create('/xb/api/layout/node/1', method: 'PATCH', content: \json_encode([
      'model' => [
        'resolved' => [
          'label' => $new_label,
          'label_display' => '',
        ],
      ],
      'componentType' => 'block.system_messages_block',
      'componentInstanceUuid' => $globalComponentUuid,
    ], JSON_THROW_ON_ERROR)));
  }

}
