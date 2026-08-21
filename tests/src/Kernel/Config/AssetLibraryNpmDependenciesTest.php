<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\ExtensionNpmDependencies;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the computed `npmDependencies` property on AssetLibrary.
 *
 * A module whose code component JavaScript is published on npm declares the
 * package and version in its info file. The CLI reads it from the global asset
 * library so the paired project depends on the same version.
 */
#[CoversClass(ExtensionNpmDependencies::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class AssetLibraryNpmDependenciesTest extends CanvasKernelTestBase {

  public function testIsEmptyWhenNoExtensionDeclaresAny(): void {
    $normalized = self::getGlobalAssetLibrary()->normalizeForClientSide()->values;
    // An object, so an empty set serializes as `{}` rather than `[]`.
    self::assertIsObject($normalized['npmDependencies']);
    self::assertSame([], (array) $normalized['npmDependencies']);
  }

  public function testExposesDeclaredPackages(): void {
    $this->enableModules(['canvas_test_npm_dependency']);

    $representation = self::getGlobalAssetLibrary()->normalizeForClientSide();
    // Only the well-formed declaration survives: the test module also declares
    // a range, a URL, and an invalid name, none of which may reach a project.
    self::assertSame(
      ['@canvas-test/declared-package' => '1.2.3'],
      (array) $representation->values['npmDependencies'],
    );
    // Installing or uninstalling an extension must invalidate the response.
    self::assertContains('config:core.extension', $representation->getCacheTags());
  }

  public function testValidatesDeclarations(): void {
    self::assertTrue(ExtensionNpmDependencies::isValidDeclaration('@acme/canvas-forms', '1.2.0'));
    self::assertTrue(ExtensionNpmDependencies::isValidDeclaration('lodash', '4.17.21'));
    self::assertTrue(ExtensionNpmDependencies::isValidDeclaration('pkg', '2.0.0-beta.1'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('lodash', '^4.0.0'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('lodash', 'latest'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('lodash', 'https://evil.example/x.tgz'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('lodash', 'file:../x'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('Not A Package', '1.0.0'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('../escape', '1.0.0'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('', '1.0.0'));
    self::assertFalse(ExtensionNpmDependencies::isValidDeclaration('lodash', ''));
  }

  public function testIsReadOnly(): void {
    $entity = self::getGlobalAssetLibrary();

    // A client that sends back the object it received must not be able to
    // write the computed property, nor make saving fail.
    $entity->updateFromClientSide(['npmDependencies' => ['evil' => '0.0.1']]);
    $entity->save();

    $reloaded = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($reloaded);
    self::assertSame([], (array) $reloaded->normalizeForClientSide()->values['npmDependencies']);
    self::assertArrayNotHasKey('npmDependencies', $reloaded->toArray());
  }

  private static function getGlobalAssetLibrary(): AssetLibrary {
    $entity = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    self::assertNotNull($entity);
    return $entity;
  }

}
