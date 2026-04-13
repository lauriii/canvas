<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

// cspell:ignore Bwidth Fitok DNSF

use Drupal\canvas\Controller\ApiMediaControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\file\Upload\FileUploadLocationTrait;
use Drupal\media\MediaTypeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\MockFileUploadTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiMediaControllers::class)]
#[CoversMethod(ApiMediaControllers::class, 'upload')]
class ApiMediaControllersPostTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use MediaTypeCreationTrait;
  use MockFileUploadTrait;
  use RequestTrait;
  use VfsPublicStreamUrlTrait;
  use FileUploadLocationTrait;
  use PredictableImageStyleItokTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/media/%s/upload';

  private string $testImagePath;

  private MediaTypeInterface $imageMediaType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);

    $this->setupPredictableItok();

    $this->imageMediaType = $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Image',
    ]);
    $this->createMediaType('video_file', [
      'id' => 'video',
      'label' => 'Video',
    ]);
    $this->setUpCurrentUser([], ['access content', 'view media', 'create media']);

    $this->mockFileSystemForUploads();

    // Copy a test image into the vfsStream-backed temporary directory so
    // FileUploadHandler can move it to the public:// destination.
    $source = \dirname(__DIR__, 3) . '/fixtures/images/gracie-big.jpg';
    $temp_dir = $this->container->get('file_system')->getTempDirectory();
    $this->testImagePath = $temp_dir . '/canvas-test-upload-' . \uniqid() . '.jpg';
    \copy($source, $this->testImagePath);
  }

  /**
   * Tests uploading a media file via the HTTP API.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiMediaControllers::upload
   */
  #[DataProvider('providerValidPost')]
  public function testPost(string $media_type, array $post_data, array $expected_response_contents): void {
    $response = $this->request(
      Request::create(
        \sprintf(self::URL, $media_type),
        'POST',
        parameters: $post_data,
        files: [
          'file' => new UploadedFile($this->testImagePath, $post_data['file'], 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );
    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);
    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

    $data = $this->decodeResponse($response);

    $vfs_site_base_url = base_path() . $this->siteDirectory;
    $media_path = $this->getMediaPath();
    \array_walk_recursive($data, function (mixed &$value) use ($vfs_site_base_url, $media_path) {
      if (\is_string($value)) {
        $value = \str_replace([$vfs_site_base_url, $media_path], ['::SITE_DIR_BASE_URL::', '::MEDIA_FOLDER::'], $value);
      }
    });

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      [
        'id' => $expected_response_contents['id'],
        // But we cannot know in advance the UUID, so just take that from
        // the response itself.
        'uuid' => $data['uuid'],
      ] + $expected_response_contents,
      $data
    );
  }

  #[DataProvider('providerInvalidPost')]
  public function testInvalidPost(string $media_type, array $post_data, int $expected_http_code, string $expected_message): void {
    $response = $this->request(
      Request::create(
        \sprintf(self::URL, $media_type),
        'POST',
        parameters: $post_data,
        files: [
          'file' => new UploadedFile($this->testImagePath, $post_data['file'], 'image/jpeg', NULL, test: TRUE),
        ],
        server: ['CONTENT_TYPE' => 'multipart/form-data'],
      )
    );

    // The response of a POST request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);
    $this->assertSame($expected_http_code, $response->getStatusCode());

    $data = $this->decodeResponse($response);
    $this->assertSame($expected_message, $data['errors'][0]['detail']);
  }

  public function testPostWithoutFile(): void {
    $request = Request::create(
      \sprintf(self::URL, 'image'),
      'POST',
      server: ['CONTENT_TYPE' => 'multipart/form-data'],
    );
    // Bypass the OpenAPI request validator (dev-only middleware) so we can
    // test the controller's own null-file handling, which is the production
    // safety net.
    $request->headers->set('X-NO-OPENAPI-VALIDATION', '1');
    $response = $this->request($request);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $data = $this->decodeResponse($response);
    $this->assertSame('No file was uploaded. The "file" field is required.', $data['errors'][0]['detail']);
  }

  public static function providerValidPost(): \Generator {
    yield "Create a new image" => [
      'image',
      [
        'file' => 'gracie-big.jpg',
        'title' => 'Gracie Dog',
        'alt' => 'Gracie Dog in its most happy state',
      ],
      [
        'id' => 1,
        'inputs_resolved' => [
          'src' => '::SITE_DIR_BASE_URL::/files/::MEDIA_FOLDER::/gracie-big.jpg?alternateWidths=::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/::MEDIA_FOLDER::/gracie-big.jpg.avif%3Fitok%3Dh5xv7Qhl',
          'alt' => 'Gracie Dog in its most happy state',
          'width' => 3000,
          'height' => 2595,
        ],
      ],
    ];
    yield "Create a new image without alt nor title" => [
      'image',
      [
        'file' => 'gracie-big.jpg',
      ],
      [
        'id' => 1,
        'inputs_resolved' => [
          'src' => '::SITE_DIR_BASE_URL::/files/::MEDIA_FOLDER::/gracie-big.jpg?alternateWidths=::SITE_DIR_BASE_URL::/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/::MEDIA_FOLDER::/gracie-big.jpg.avif%3Fitok%3Dh5xv7Qhl',
          'alt' => '',
          'width' => 3000,
          'height' => 2595,
        ],
      ],
    ];
  }

  public static function providerInvalidPost(): \Generator {
    yield "Create a new media with non-image media type" => [
      'video',
      [
        'file' => 'gracie-big.jpg',
      ],
      Response::HTTP_BAD_REQUEST,
      "The media type 'video' is not an image media type.",
    ];
    yield "Create a new media with invalid file extension" => [
      'image',
      [
        'file' => 'gracie-big.exe',
      ],
      Response::HTTP_UNPROCESSABLE_ENTITY,
      'Only files with the following extensions are allowed: <em class="placeholder">png gif jpg jpeg webp avif</em>.',
    ];
  }

  private function getMediaPath(): string {
    $source_field_definition = $this->imageMediaType->getSource()->getSourceFieldDefinition($this->imageMediaType);
    \assert($source_field_definition instanceof FieldDefinitionInterface);
    $location = $this->getUploadLocation($source_field_definition);
    // Location will be on the form of 'public://2026-03'
    return str_replace('public://', '', $location);
  }

}
