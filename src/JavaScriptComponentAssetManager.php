<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Component\Utility\DeprecationHelper;
use Drupal\Core\Cache\CacheCollectorInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;

/**
 * JavaScript component asset manager service.
 */
final class JavaScriptComponentAssetManager {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
  ) {}

  public function generateFiles(JavaScriptComponent $component): void {
    $this->write($component->getCssPath(), $component->getCompiledCss());
    $this->write($component->getJsPath(), $component->getCompiledJs());

    DeprecationHelper::backwardsCompatibleCall(
      \Drupal::VERSION,
      '11.1',
      fn () => \assert($this->libraryDiscovery instanceof CacheCollectorInterface) && $this->libraryDiscovery->clear(),
      // @phpstan-ignore-next-line
      fn () => \Drupal::service('library.discovery.collector')->clear(),
    );
  }

  private function write(string $filename, string $data): void {
    if (trim($data)) {
      $dir = dirname($filename);
      $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->saveData($data, $filename, FileExists::Replace);
    }
  }

}
