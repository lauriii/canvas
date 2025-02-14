<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\MediaInterface;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Drupal\experience_builder\Controller\ApiLayoutController::patch()
 * @group experience_builder
 */
final class ApiLayoutControllerPatchTest extends KernelTestBase {

  use RequestTrait {
    request as parentRequest;
  }
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], ['access administration pages', 'administer url aliases']);
  }

  /**
   * @param class-string<\Throwable> $exception
   * @dataProvider providerInvalid
   */
  public function testInvalid(string $message, string $exception, array $content): void {
    $this->expectException($exception);
    $this->expectExceptionMessage($message);
    $this->parentRequest(Request::create('/api/layout/node/1', method: 'PATCH', server: [
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
    if ($withGlobal) {
      $template = PageTemplate::createFromBlockLayout('stark');
      $template->enable()->save();
    }
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/api/layout/node/1'))->getContent();
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

    if ($withAutoSave) {
      // Perform a POST first to trigger an auto-save entry.
      $response = $this->request(Request::create('/api/layout/node/1', method: 'POST', content: $content));
      self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    // Update the image.
    $media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['name' => 'Hero image']);
    self::assertCount(1, $media);
    $media = reset($media);
    \assert($media instanceof MediaInterface);

    // Make sure the current value isn't the same media ID.
    self::assertNotEquals($media->id(), $model['static-image-udf7d']['resolved']['image']);

    // Now patch the layout.
    $response = $this->request(Request::create('/api/layout/node/1', method: 'PATCH', content: \json_encode([
      'model' => [
        'resolved' => [
          'image' => $media->id(),
        ],
      ] + $model['static-image-udf7d'],
      'componentType' => 'sdc.experience_builder.image',
      'componentInstanceUuid' => 'static-image-udf7d',
    ], JSON_THROW_ON_ERROR)));

    // The new model should contain the updated value.
    $data = self::decodeResponse($response);
    self::assertEquals($media->id(), $data['model']['static-image-udf7d']['resolved']['image']);

    // Check that each level is structured correctly.
    $root = $this->cssSelect('main div[data-xb-region][data-xb-uuid="content"]');
    self::assertNotEmpty($root);
    $globalElements = [];
    if ($withGlobal) {
      $highlighted = $this->cssSelect('div[data-xb-region][data-xb-uuid="highlighted"]');
      self::assertNotEmpty($highlighted);
      \preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $highlighted[0]->asXML() ?: '', $comments);
      $globalElements = $comments[2];
    }
    self::assertGreaterThan(0, $root[0]->count());
    \preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $root[0]->asXML() ?: '', $comments);
    self::assertCount($withGlobal ? 7 : 6, \array_merge($comments[2], $globalElements));
    if ($withGlobal) {
      self::assertSame(\array_keys($model), \array_merge($comments[2], $globalElements));
    }

    // The updated preview should reference the new image.
    $file = $media->get('field_media_image')->entity;
    \assert($file instanceof FileInterface);
    $fileUri = $file->getFileUri();
    \assert(is_string($fileUri));
    $image_url = $this->container->get(FileUrlGeneratorInterface::class)->generateString($fileUri);
    $images = $this->cssSelect(\sprintf('img[src="%s"]', $image_url));
    self::assertCount(1, $images);
  }

  public static function providerValid(): iterable {
    yield 'fresh state, no global' => [];
    yield 'fresh state, global' => [FALSE, TRUE];
    yield 'existing autosave, no global' => [TRUE, FALSE];
    yield 'existing autosave, global' => [TRUE, TRUE];
  }

  private static function decodeResponse(Response $response): array {
    self::assertIsString($response->getContent());
    self::assertJson($response->getContent());
    return \json_decode($response->getContent(), TRUE);
  }

  /**
   * Unwrap the JSON response so we can perform assertions on it.
   */
  protected function request(Request $request): Response {
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->parentRequest($request);
    $this->setRawContent(static::decodeResponse($response)['html']);
    return $response;
  }

}
