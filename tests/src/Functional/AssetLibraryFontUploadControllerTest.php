<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\BrandKit;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests generic artifact uploads used by Brand Kit fonts.
 *
 * @internal
 * @legacy-covers \Drupal\canvas\Controller\ApiArtifactController
 */
#[Group('canvas')]
final class AssetLibraryFontUploadControllerTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected readonly UserInterface $limitedPermissionsUser;

  protected function setUp(): void {
    parent::setUp();

    $user = $this->createUser([BrandKit::ADMIN_PERMISSION]);
    \assert($user instanceof UserInterface);
    $this->httpApiUser = $user;

    $limited_user = $this->createUser(['view media']);
    \assert($limited_user instanceof UserInterface);
    $this->limitedPermissionsUser = $limited_user;
  }

  public function testFontUpload(): void {
    $path = tempnam(sys_get_temp_dir(), 'canvas-font');
    self::assertNotFalse($path);
    file_put_contents($path, 'font-data');

    $this->drupalLogin($this->httpApiUser);

    $body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.woff2'),
      Response::HTTP_CREATED,
      NULL,
      NULL,
      NULL,
      NULL,
    );

    self::assertIsArray($body);
    self::assertIsInt($body['fid']);
    self::assertStringStartsWith(BrandKit::ARTIFACTS_DIRECTORY, $body['uri']);
    self::assertStringContainsString('.woff2', $body['url']);

    $file = \Drupal::entityTypeManager()->getStorage('file')->load($body['fid']);
    \assert($file instanceof FileInterface);
    self::assertTrue($file->isTemporary());
  }

  public function testUploadingDuplicateFilenameCreatesUniqueArtifact(): void {
    $path = tempnam(sys_get_temp_dir(), 'canvas-font');
    self::assertNotFalse($path);
    file_put_contents($path, 'font-data');

    $this->drupalLogin($this->httpApiUser);

    $first_body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.woff2'),
      Response::HTTP_CREATED,
      NULL,
      NULL,
      NULL,
      NULL,
    );
    $second_body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.woff2'),
      Response::HTTP_CREATED,
      NULL,
      NULL,
      NULL,
      NULL,
    );

    self::assertIsArray($first_body);
    self::assertIsArray($second_body);
    self::assertNotSame($first_body['fid'], $second_body['fid']);
    self::assertNotSame($first_body['uri'], $second_body['uri']);
    self::assertStringStartsWith(BrandKit::ARTIFACTS_DIRECTORY, $second_body['uri']);
    self::assertStringContainsString('.woff2', $second_body['url']);
  }

  public function testUnsupportedExtensionIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'canvas-font');
    self::assertNotFalse($path);
    file_put_contents($path, 'font-data');

    $this->drupalLogin($this->httpApiUser);

    $body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.txt'),
      Response::HTTP_UNPROCESSABLE_ENTITY,
      NULL,
      NULL,
      NULL,
      NULL,
    );

    self::assertIsArray($body);
    self::assertStringContainsString(
      'Only files with the following extensions are allowed',
      $body['errors'][0],
    );
  }

  public function testOversizedFileIsRejected(): void {
    $path = tempnam(sys_get_temp_dir(), 'canvas-font');
    self::assertNotFalse($path);
    file_put_contents($path, str_repeat('a', 11 * 1024 * 1024));

    $this->drupalLogin($this->httpApiUser);

    $body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.woff2'),
      Response::HTTP_UNPROCESSABLE_ENTITY,
      NULL,
      NULL,
      NULL,
      NULL,
    );

    self::assertIsArray($body);
    self::assertStringContainsString(
      \sprintf('maximum file size of %s', self::getExpectedUploadLimitLabel()),
      $body['errors'][0],
    );
  }

  public function testUploadRequiresPermission(): void {
    $path = tempnam(sys_get_temp_dir(), 'canvas-font');
    self::assertNotFalse($path);
    file_put_contents($path, 'font-data');

    $this->drupalLogin($this->limitedPermissionsUser);

    $body = $this->assertExpectedResponse(
      'POST',
      Url::fromUri('base:/canvas/api/v0/artifacts/upload'),
      self::createUploadRequestOptions($path, 'brand-font.woff2'),
      Response::HTTP_FORBIDDEN,
      NULL,
      NULL,
      NULL,
      NULL,
    );

    self::assertSame([
      'errors' => ["Either the 'administer brand kit' permission or the 'administer code components' permission is required."],
    ], $body);
  }

  /**
   * Creates streamed upload request options.
   */
  private static function createUploadRequestOptions(string $path, string $filename): array {
    return [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/octet-stream',
        'Content-Disposition' => \sprintf('file; filename="%s"', $filename),
      ],
      RequestOptions::BODY => (string) file_get_contents($path),
    ];
  }

  private static function getExpectedUploadLimitLabel(): string {
    return '10 MB';
  }

}
