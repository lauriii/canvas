<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\file\Entity\File;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\InputStreamFileWriterInterface;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tests the ApiArtifactController for push/pull API endpoints.
 */
#[Group('canvas')]
class ApiArtifactControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->createUser();
    $user = $this->createUser([JavaScriptComponent::ADMIN_PERMISSION]);
    self::assertNotFalse($user);
    $this->setCurrentUser($user);
  }

  /**
   * Tests uploading a CSS artifact persists a managed file.
   */
  public function testUploadCssArtifact(): void {
    $css = ".canvas-test {\n  color: rebeccapurple;\n}\n";
    $temp_path = 'temporary://artifact-upload.css';
    \file_put_contents($temp_path, $css);
    $file_writer = $this->createMock(InputStreamFileWriterInterface::class);
    $file_writer
      ->method('writeStreamToFile')
      ->willReturn($temp_path);
    $this->container->set(InputStreamFileWriterInterface::class, $file_writer);
    $this->container->set('file.input_stream_file_writer', $file_writer);

    $request = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="artifact.css"',
      ],
      content: $css,
    );

    $response = $this->request($request);
    self::assertSame(201, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertArrayHasKey('uri', $data);
    self::assertArrayHasKey('fid', $data);
    self::assertIsString($data['uri']);
    self::assertIsInt($data['fid']);

    $file = File::load($data['fid']);
    self::assertNotNull($file);

    self::assertStringEndsWith('.css', (string) $file->getFilename());

    $file_uri = $file->getFileUri();
    self::assertIsString($file_uri);
    self::assertSame($data['uri'], $file_uri);

    $stored_css = \file_get_contents($file_uri);
    self::assertNotFalse($stored_css);
    self::assertSame($css, $stored_css);

    $file_usage = $this->container->get(FileUsageInterface::class);
    self::assertSame([], $file_usage->listUsage($file));
  }

  /**
   * Tests CLI uploads return existing file on duplicate instead of replacing.
   */
  public function testCliUploadReturnsDuplicateFile(): void {
    $js = "console.log('test');\n";
    $temp_path = 'temporary://cli-artifact.js';
    \file_put_contents($temp_path, $js);
    $file_writer = $this->createMock(InputStreamFileWriterInterface::class);
    $file_writer
      ->method('writeStreamToFile')
      ->willReturn($temp_path);
    $this->container->set(InputStreamFileWriterInterface::class, $file_writer);
    $this->container->set('file.input_stream_file_writer', $file_writer);

    // First upload with CLI header - should create file with 201 status
    $request = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="test-abc123.js"',
        'HTTP_X_CANVAS_CLI' => '1',
      ],
      content: $js,
    );

    $response = $this->request($request);
    self::assertSame(201, $response->getStatusCode());
    $data = self::decodeResponse($response);
    self::assertArrayHasKey('uri', $data);
    self::assertArrayHasKey('fid', $data);
    self::assertArrayHasKey('url', $data);

    $original_fid = $data['fid'];
    $original_uri = $data['uri'];
    $original_url = $data['url'];

    $file = File::load($original_fid);
    self::assertNotNull($file);

    // Second upload with same filename and CLI header - should return existing file with 200 status
    $request2 = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="test-abc123.js"',
        'HTTP_X_CANVAS_CLI' => '1',
      ],
      content: $js,
    );

    $response2 = $this->request($request2);
    self::assertSame(200, $response2->getStatusCode());
    $data2 = self::decodeResponse($response2);

    // Verify we got the same file back
    self::assertSame($original_fid, $data2['fid']);
    self::assertSame($original_uri, $data2['uri']);
    self::assertSame($original_url, $data2['url']);

    // Verify the file entity was not replaced (still only one file entity)
    $all_files = File::loadMultiple();
    $js_files = \array_filter($all_files, fn($f) => str_contains((string) $f->getFilename(), 'test-abc123.js'));
    self::assertCount(1, $js_files);
  }

  /**
   * Tests non-CLI uploads still use rename behavior for duplicates.
   */
  public function testNonCliUploadRenamesDuplicateFile(): void {
    $js = "console.log('brand-kit');\n";
    $temp_path1 = 'temporary://brand-kit-1.js';
    $temp_path2 = 'temporary://brand-kit-2.js';
    \file_put_contents($temp_path1, $js);
    \file_put_contents($temp_path2, $js);

    $file_writer = $this->createMock(InputStreamFileWriterInterface::class);
    $file_writer
      ->method('writeStreamToFile')
      ->willReturnOnConsecutiveCalls($temp_path1, $temp_path2);
    $this->container->set(InputStreamFileWriterInterface::class, $file_writer);

    // First upload without CLI header
    $request = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="brand-font.js"',
      ],
      content: $js,
    );

    $response = $this->request($request);
    self::assertSame(201, $response->getStatusCode());
    $data = self::decodeResponse($response);

    $original_fid = $data['fid'];
    $original_uri = $data['uri'];

    // Second upload with same filename, no CLI header - should rename and create new file
    $request2 = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="brand-font.js"',
      ],
      content: $js,
    );

    $response2 = $this->request($request2);
    self::assertSame(201, $response2->getStatusCode());
    $data2 = self::decodeResponse($response2);

    // Verify we got a different file with renamed URI
    self::assertNotSame($original_fid, $data2['fid']);
    self::assertNotSame($original_uri, $data2['uri']);

    // Verify both files exist
    $file1 = File::load($original_fid);
    self::assertNotNull($file1);
    $file2 = File::load($data2['fid']);
    self::assertNotNull($file2);

    // Second file should have a renamed filename (e.g., brand-font_0.js)
    self::assertStringStartsWith('brand-font', (string) $file2->getFilename());
    self::assertNotEquals($file1->getFilename(), $file2->getFilename());
  }

  /**
   * Tests upload returns 503 + Retry-After when lock cannot be acquired.
   */
  public function testUploadMapsLockAcquiringException(): void {
    $upload_handler = $this->createMock(FileUploadHandlerInterface::class);
    $upload_handler
      ->method('handleFileUpload')
      ->willThrowException(new LockAcquiringException('Lock busy'));
    $this->container->set(FileUploadHandlerInterface::class, $upload_handler);
    $this->container->set('file.upload_handler', $upload_handler);

    $request = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="artifact.js"',
      ],
      content: 'console.log("artifact");',
    );

    try {
      $this->request($request);
      self::fail('Expected a HttpException to be thrown.');
    }
    catch (HttpException $e) {
      self::assertSame(503, $e->getStatusCode());
      self::assertSame('Lock busy', $e->getMessage());
      self::assertSame(['Retry-After' => 1], $e->getHeaders());
    }
  }

  /**
   * Tests upload translates upload result violations to 422.
   */
  public function testUploadMapsViolationsToUnprocessableEntity(): void {
    $exe_content = 'MZ';
    $temp_path = 'temporary://artifact-upload.exe';
    \file_put_contents($temp_path, $exe_content);
    $file_writer = $this->createMock(InputStreamFileWriterInterface::class);
    $file_writer
      ->method('writeStreamToFile')
      ->willReturn($temp_path);
    $this->container->set(InputStreamFileWriterInterface::class, $file_writer);

    $request = Request::create(
      '/canvas/api/v0/artifacts/upload',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_CONTENT_DISPOSITION' => 'attachment; filename="artifact.exe"',
      ],
      content: $exe_content,
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage("Unprocessable Entity: file validation failed.\nOnly files with the following extensions are allowed:");
    $this->request($request);
  }

}
