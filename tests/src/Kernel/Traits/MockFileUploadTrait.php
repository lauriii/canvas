<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\Core\File\FileSystemInterface;

/**
 * Mocks the file system so multipart/form-data uploads work in kernel tests.
 *
 * In kernel tests, move_uploaded_file() fails because the file wasn't actually
 * uploaded via HTTP. This trait replaces the file_system service with a mock
 * that uses copy() instead of moveUploadedFile(), while delegating all other
 * methods to the real file system.
 *
 * This is needed for routes that accept multipart/form-data (e.g. media
 * upload), which use FormUploadedFile / move_uploaded_file() internally.
 * It is NOT needed for routes using application/octet-stream (e.g. artifact
 * upload), which read from php://input directly.
 */
trait MockFileUploadTrait {

  /**
   * Replaces the file_system service with a mock that supports uploads.
   */
  private function mockFileSystemForUploads(): void {
    // TRICKY: In kernel tests, move_uploaded_file() fails because the file wasn't
    // actually uploaded via HTTP. Mock the file system to use copy() instead.
    $real_file_system = $this->container->get('file_system');
    $mock_file_system = $this->createMock(FileSystemInterface::class);
    $mock_file_system->method('moveUploadedFile')
      ->willReturnCallback(fn($filename, $uri) => (bool) $real_file_system->copy($filename, $uri));
    // Delegate all other methods to the real file system.
    foreach (['move', 'copy', 'delete', 'deleteRecursive', 'mkdir', 'chmod', 'dirname', 'basename', 'prepareDirectory', 'createFilename', 'getDestinationFilename', 'realpath', 'tempnam', 'getTempDirectory'] as $method) {
      $mock_file_system->method($method)->willReturnCallback($real_file_system->$method(...));
    }
    $this->container->set('file_system', $mock_file_system);
  }

}
