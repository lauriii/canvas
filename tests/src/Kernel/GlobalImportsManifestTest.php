<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that GlobalImports includes manifest entries in the import map.
 */
#[Group("canvas")]
class GlobalImportsManifestTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Tests that manifest entries are included in global imports.
   */
  public function testManifestEntriesInImportMap(): void {
    // Set up entries on the entity.
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);

    $entity->setImports([
      [
        'name' => 'motion',
        /* cspell:disable-next-line */
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'vendor/motion-l0sNRNKZ.js',
      ],
    ]);
    $entity->setAssets([
      [
        'name' => '@/components/hero/index.js',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'components/hero/index.js',
      ],
    ]);
    $entity->save();

    $global_imports = $this->container->get(GlobalImports::class);
    $imports = $global_imports->getImportMap();

    // Manifest entries should be present.
    self::assertArrayHasKey('motion', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
    self::assertArrayHasKey('@/components/hero/index.js', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);

    // URLs should contain the file path and a cache-busting query string.
    /* cspell:disable-next-line */
    self::assertStringContainsString('vendor/motion-l0sNRNKZ.js', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]['motion']);
    self::assertStringContainsString('?', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]['motion']);
  }

  /**
   * Tests that base imports are still present with manifest.
   */
  public function testBaseImportsPreservedWithManifest(): void {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);
    $entity->setImports([
      [
        'name' => 'custom-lib',
        'uri' => AssetLibrary::ARTIFACTS_DIRECTORY . 'custom-lib.js',
      ],
    ]);
    $entity->save();

    $global_imports = $this->container->get(GlobalImports::class);
    $imports = $global_imports->getImportMap();

    // Core imports should still be present.
    self::assertArrayHasKey('preact', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
    self::assertArrayHasKey('react', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
    self::assertArrayHasKey('clsx', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);

    // Custom import should also be present.
    self::assertArrayHasKey('custom-lib', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
  }

  /**
   * Tests that no manifest entries are added when all properties are null.
   */
  public function testNoManifestEntriesWhenNull(): void {
    $global_imports = $this->container->get(GlobalImports::class);
    $imports = $global_imports->getImportMap();

    // Only base imports should be present.
    self::assertArrayHasKey('preact', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
    self::assertArrayNotHasKey('motion', $imports[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS]);
  }

}
