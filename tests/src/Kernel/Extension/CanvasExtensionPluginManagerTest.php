<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Extension;

use Drupal\canvas\Exception\ExtensionValidationException;
use Drupal\canvas\Extension\CanvasExtension;
use Drupal\canvas\Extension\CanvasExtensionPluginManager;
use Drupal\canvas\Extension\CanvasExtensionTypeEnum;
use Drupal\Component\Assertion\Inspector;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[CoversClass(CanvasExtensionPluginManager::class)]
class CanvasExtensionPluginManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    'user',
    'canvas_test_extension',
  ];

  public function testGetDefinitions(): void {
    $module_location = $this->getModulePath('canvas_test_extension');
    /** @var \Drupal\canvas\Extension\CanvasExtensionPluginManager $extension_manager */
    $extension_manager = $this->container->get(CanvasExtensionPluginManager::class);
    $definitions = $extension_manager->getDefinitions();
    $this->assertCount(1, $definitions);
    \assert(Inspector::assertAllObjects($definitions, CanvasExtension::class));

    /** @var \Drupal\canvas\Extension\CanvasExtensionInterface $canvas_extension */
    $canvas_extension = $extension_manager->getDefinition('canvas_test_extension');

    // Defined properties.
    self::assertSame('Canvas Test Extension', $canvas_extension->label());
    self::assertSame('Demonstrates what a Canvas extension can do', $canvas_extension->getDescription());
    self::assertSame(['access content'], $canvas_extension->getPermissions());
    // Url is processed as it's relative to the module root.
    self::assertSame('/' . $module_location . '/index.html', $canvas_extension->getUrl());
    self::assertSame('/' . $module_location . '/icon.svg', $canvas_extension->getIcon());
    self::assertSame(CanvasExtensionTypeEnum::Canvas, $canvas_extension->getType());
    self::assertSame('1.0', $canvas_extension->getApiVersion());
  }

  public function testGetMultipleDefinitionsInOneModule(): void {
    $module_location = $this->getModulePath('canvas_test_extension_multiple');
    $this->enableModules(['canvas_test_extension_multiple']);

    /** @var \Drupal\canvas\Extension\CanvasExtensionPluginManager $extension_manager */
    $extension_manager = $this->container->get(CanvasExtensionPluginManager::class);
    $definitions = $extension_manager->getDefinitions();
    $this->assertCount(3, $definitions);
    \assert(Inspector::assertAllObjects($definitions, CanvasExtension::class));

    /** @var \Drupal\canvas\Extension\CanvasExtensionInterface $first_extension */
    $first_extension = $definitions['canvas_test_extension_multiple'];
    /** @var \Drupal\canvas\Extension\CanvasExtensionInterface $second_extension */
    $second_extension = $definitions['canvas_test_yet_another_extension'];

    self::assertSame('Canvas Test Multiple Extension (First)', $first_extension->label());
    self::assertSame('Demonstrates many things that multiple Canvas extensions can do', $first_extension->getDescription());
    self::assertSame(['access content'], $first_extension->getPermissions());
    self::assertSame('/' . $module_location . '/ui/dist/index.html', $first_extension->getUrl());
    self::assertSame('/' . $module_location . '/icon-1.svg', $first_extension->getIcon());
    self::assertSame(CanvasExtensionTypeEnum::CodeEditor, $first_extension->getType());
    self::assertSame('1.0', $first_extension->getApiVersion());

    self::assertSame('Canvas Test Multiple Extension (Second)', $second_extension->label());
    self::assertSame('Demonstrates many things that multiple Canvas extensions can do', $second_extension->getDescription());
    self::assertSame(['access content', 'administer components'], $second_extension->getPermissions());
    self::assertSame('https://example.org/canvas-extension.html', $second_extension->getUrl());
    self::assertSame('', $second_extension->getIcon());
    self::assertSame(CanvasExtensionTypeEnum::Canvas, $second_extension->getType());
    self::assertSame('0.1', $second_extension->getApiVersion());
  }

  #[DataProvider('providerInvalidDefinitions')]
  public function testInvalidDefinitions(string $module, string $exceptionMessage): void {
    $this->enableModules([$module]);

    $this->expectException(ExtensionValidationException::class);
    $this->expectExceptionMessage($exceptionMessage);

    /** @var \Drupal\canvas\Extension\CanvasExtensionPluginManager $extension_manager */
    $extension_manager = $this->container->get(CanvasExtensionPluginManager::class);
    $extension_manager->getDefinitions();
  }

  public static function providerInvalidDefinitions(): \Generator {
    yield 'Invalid Icon' => [
      'canvas_test_extension_invalid_icon',
      'The extension canvas_test_extension_invalid_icon in module canvas_test_extension_invalid_icon path icon cannot start with "/". Use an absolute url or a path relative to your module info.yml file.',
    ];
    yield 'Invalid URL' => [
      'canvas_test_extension_invalid_url',
      'The extension canvas_test_extension_invalid_url in module canvas_test_extension_invalid_url path url cannot start with "/". Use an absolute url or a path relative to your module info.yml file.',
    ];
    yield 'No version' => [
      'canvas_test_extension_invalid_version',
      'The extension canvas_test_extension_invalid_version in module canvas_test_extension_invalid_version must define its api_version.',
    ];

  }

}
