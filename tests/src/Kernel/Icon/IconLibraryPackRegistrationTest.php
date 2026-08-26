<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Icon;

use Drupal\canvas\Entity\IconLibrary;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\Icon\IconDefinition;
use Drupal\Core\Theme\Icon\IconDefinitionInterface;
use Drupal\Core\Theme\Icon\Plugin\IconPackManagerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that icon library config entities register as core icon packs.
 *
 * @see \Drupal\canvas\Hook\IconPackHooks
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class IconLibraryPackRegistrationTest extends CanvasKernelTestBase {

  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_icons',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Tests pack registration, icon discovery, and cache invalidation.
   */
  public function testPackRegistration(): void {
    $file_system = $this->container->get(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $directory = IconLibrary::ASSETS_DIRECTORY . 'demo/';
    self::assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS));
    $fixtures_directory = \dirname(__DIR__, 3) . '/modules/canvas_test_icons/icons';
    foreach (['star.svg', 'heart.svg'] as $filename) {
      $contents = \file_get_contents($fixtures_directory . '/' . $filename);
      self::assertIsString($contents);
      self::assertNotFalse(\file_put_contents($directory . $filename, $contents));
    }

    $icon_pack_manager = $this->container->get('plugin.manager.icon_pack');
    \assert($icon_pack_manager instanceof IconPackManagerInterface);

    // Before the icon library exists, only extension-provided packs exist.
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertArrayHasKey('canvas_test', $definitions);
    self::assertArrayNotHasKey('demo', $definitions);

    // A stray file in the directory that the entity never references (for
    // example a partially failed upload) must not become a live icon.
    self::assertNotFalse(\file_put_contents($directory . 'stray.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'));

    $library = IconLibrary::create([
      'id' => 'demo',
      'label' => 'Demo icons',
      'description' => 'A config-defined icon pack.',
      'assets' => [
        ['name' => 'star.svg', 'uri' => $directory . 'star.svg'],
        ['name' => 'heart.svg', 'uri' => $directory . 'heart.svg'],
      ],
    ]);
    $library->save();

    // The config-defined pack and the module-provided pack coexist.
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertArrayHasKey('canvas_test', $definitions);
    self::assertArrayHasKey('demo', $definitions);
    self::assertSame('canvas', $definitions['demo']['provider']);
    self::assertSame('Demo icons', $definitions['demo']['label']);
    self::assertSame(IconLibrary::DEFAULT_TEMPLATE, $definitions['demo']['template']);
    // Only the entity's own asset list becomes icons; the stray file in the
    // directory is excluded.
    self::assertEqualsCanonicalizing(['demo:heart', 'demo:star'], \array_keys($definitions['demo']['icons']));

    // Individual icons resolve through the icon collector.
    $icon = $icon_pack_manager->getIcon('demo:star');
    self::assertInstanceOf(IconDefinitionInterface::class, $icon);
    self::assertSame('star', $icon->getIconId());
    self::assertSame('demo', $icon->getPackId());

    // The definitive Icon API availability check: a config-defined pack's
    // icon renders through core's own `#type => 'icon'` render element and
    // the library's Twig template, exactly like a module-provided pack.
    $renderer = $this->container->get(RendererInterface::class);
    \assert($renderer instanceof RendererInterface);
    $build = IconDefinition::getRenderable('demo:star');
    self::assertIsArray($build);
    $html = (string) $renderer->renderInIsolation($build);
    self::assertStringContainsString('<svg', $html);
    self::assertStringContainsString('m12 3 2.6 5.9', $html);

    // Newly uploaded SVG files are discovered once the entity references them
    // and the caches are cleared, which IconLibrary::postSave() does.
    $contents = \file_get_contents($fixtures_directory . '/home.svg');
    self::assertIsString($contents);
    self::assertNotFalse(\file_put_contents($directory . 'home.svg', $contents));
    $library->setAssets([
      ['name' => 'star.svg', 'uri' => $directory . 'star.svg'],
      ['name' => 'heart.svg', 'uri' => $directory . 'heart.svg'],
      ['name' => 'home.svg', 'uri' => $directory . 'home.svg'],
    ]);
    $library->save();
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertEqualsCanonicalizing(['demo:heart', 'demo:home', 'demo:star'], \array_keys($definitions['demo']['icons']));

    // Deleting the entity unregisters the pack: cached definitions and the
    // icon collector are invalidated by IconLibrary::postDelete().
    $library->delete();
    $definitions = $icon_pack_manager->getDefinitions() ?? [];
    self::assertArrayNotHasKey('demo', $definitions);
    self::assertArrayHasKey('canvas_test', $definitions);
    self::assertNull($icon_pack_manager->getIcon('demo:star'));
  }

}
