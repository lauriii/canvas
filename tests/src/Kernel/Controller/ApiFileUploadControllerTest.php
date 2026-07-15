<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiFileUploadController;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\file\Entity\File;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\MockFileUploadTrait;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiFileUploadController::class)]
final class ApiFileUploadControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use MockFileUploadTrait;
  use RequestTrait;
  use VfsPublicStreamUrlTrait;
  use PredictableImageStyleItokTestTrait;
  use GenerateComponentConfigTrait;

  private const string URL = '/canvas/api/v0/file/upload';

  /**
   * A component whose `image` prop is stored as an `image` field.
   *
   * Because no image media types exist in this test, the `image`-shaped prop
   * falls back to the `image` field type with the `image_image` widget.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   */
  private const string COMPONENT_ID = 'sdc.canvas_test_sdc.card';

  /**
   * The active version of the tested component.
   */
  private string $version;

  private string $testImagePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    $this->setupPredictableItok();

    $this->generateComponentConfig();
    $component = Component::load(self::COMPONENT_ID);
    \assert($component instanceof ComponentInterface);
    self::assertSame('image', $component->getSettings()['prop_field_definitions']['image']['field_type']);
    self::assertSame('image_image', $component->getSettings()['prop_field_definitions']['image']['field_widget']);
    $this->version = $component->getActiveVersion();

    // `administer code components` grants Canvas UI access.
    // @see \Drupal\canvas\Access\CanvasUiAccessCheck
    $this->setUpCurrentUser([], ['administer code components', 'access content']);

    $this->mockFileSystemForUploads();

    // Copy a test image into the temporary directory so FileUploadHandler can
    // move it to the public:// destination.
    $source = \dirname(__DIR__, 3) . '/fixtures/images/gracie-big.jpg';
    $temp_dir = $this->container->get('file_system')->getTempDirectory();
    $this->testImagePath = $temp_dir . '/canvas-test-upload-' . \uniqid() . '.jpg';
    \copy($source, $this->testImagePath);
  }

  /**
   * Builds an upload request for the given query parameters and client name.
   */
  private function requestUpload(array $query, string $client_filename): Response {
    return $this->request(
      Request::create(
        self::URL . '?' . \http_build_query($query + [
          'component' => self::COMPONENT_ID,
          'version' => $this->version,
          'prop' => 'image',
        ]),
        'POST',
        files: [
          'file' => new UploadedFile($this->testImagePath, $client_filename, 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );
  }

  /**
   * Tests a valid upload for an image prop.
   */
  public function testPost(): void {
    $response = $this->requestUpload([], 'gracie-big.jpg');
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $data = self::decodeResponse($response);

    $vfs_site_base_url = base_path() . $this->siteDirectory;
    // Versioned public APIs need to be strict: this means asserting that we
    // get all the expected info, but also NO extra additions. So we use
    // `assertSame` on the full response contents.
    self::assertSame(
      [
        'fid' => 1,
        // We cannot know the UUID in advance, so take it from the response.
        'uuid' => $data['uuid'],
        'url' => $vfs_site_base_url . '/files/2026-04/gracie-big.jpg',
        'filename' => 'gracie-big.jpg',
        'filesize' => \filesize($this->testImagePath),
        'width' => 3000,
        'height' => 2595,
      ],
      $data,
    );

    // The File entity is saved permanently: its ID is about to be stored in a
    // component instance's inputs.
    $file = File::load(1);
    self::assertNotNull($file);
    self::assertTrue($file->isPermanent());
    self::assertSame($data['uuid'], $file->uuid());
  }

  /**
   * Tests that a disallowed file extension is rejected.
   */
  public function testPostDisallowedExtension(): void {
    $response = $this->requestUpload([], 'gracie-big.exe');
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $data = self::decodeResponse($response);
    // No instance-level `file_extensions` setting is stored for this prop, so
    // the `image` field type's defaults apply.
    // @see \Drupal\image\Plugin\Field\FieldType\ImageItem::defaultFieldSettings()
    self::assertSame('Only files with the following extensions are allowed: <em class="placeholder">png gif jpg jpeg webp</em>.', $data['errors'][0]['detail']);
  }

  /**
   * Tests the error paths.
   *
   * The exceptions thrown by the controller are converted to JSON error
   * responses in production, but kernel tests request with `$catch = FALSE`,
   * so assert the exceptions themselves.
   *
   * @param class-string<\Throwable> $expected_exception
   *
   * @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber
   */
  #[DataProvider('providerErrors')]
  public function testErrors(array $query_overrides, string $expected_exception, string $expected_message): void {
    $this->expectException($expected_exception);
    $this->expectExceptionMessage($expected_message);
    $this->requestUpload($query_overrides, 'gracie-big.jpg');
  }

  public static function providerErrors(): \Generator {
    yield 'prop not stored as an image or file field: 400' => [
      // The `heading` prop of card is stored as a `string` field.
      ['prop' => 'heading'],
      BadRequestHttpException::class,
      'The `heading` prop is not stored as an `image` or `file` field, so file uploads are not supported for it.',
    ];
    yield 'unknown component: 404' => [
      ['component' => 'sdc.canvas_test_sdc.nonexistent'],
      NotFoundHttpException::class,
      'The component `sdc.canvas_test_sdc.nonexistent` does not exist.',
    ];
    yield 'unknown version: 404' => [
      ['version' => 'deadbeef'],
      NotFoundHttpException::class,
      'The requested version `deadbeef` is not available.',
    ];
    yield 'unknown prop: 404' => [
      ['prop' => 'nonexistent'],
      NotFoundHttpException::class,
      \sprintf('The `%s` component does not have a `nonexistent` prop.', self::COMPONENT_ID),
    ];
  }

}
