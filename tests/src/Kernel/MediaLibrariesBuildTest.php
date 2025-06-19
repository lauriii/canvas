<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * @covers \Drupal\experience_builder\Hook\LibraryHooks::libraryInfoBuild()
 * @group experience_builder
 */
final class MediaLibrariesBuildTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'block',
    // XB's dependencies (modules providing field types + widgets).
    'datetime',
    'file',
    'image',
    'media',
    'options',
    'path',
    'link',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service(ThemeInstallerInterface::class)->install(['claro', 'olivero', 'xb_stark']);
    $this->config('system.theme')->set('default', 'olivero')->set('admin', 'claro')->save();
  }

  /**
   * Tests \experience_builder_library_info_build.
   */
  public function testLibraryBuild(): void {
    // Set olivero as the default theme.
    \Drupal::theme()->setActiveTheme(\Drupal::service(ThemeInitializationInterface::class)->initTheme('olivero'));

    $discovered = $this->container->get(LibraryDiscoveryParser::class)->buildByExtension('experience_builder');
    self::assertArrayHasKey('xb.drupal.dialog', $discovered);
    self::assertArrayHasKey('xb.drupal.ajax', $discovered);
    self::assertArrayHasKey('xb.scoped.admin.css', $discovered);
    // XB equivalent dependencies for core/drupal.ajax.
    self::assertArrayHasKey('xb.jquery', $discovered);
    self::assertArrayHasKey('xb.internal.jquery_ui', $discovered);
    self::assertArrayHasKey('xb.drupal', $discovered);
    self::assertArrayHasKey('xb.drupalSettings', $discovered);
    self::assertArrayHasKey('xb.drupal.displace', $discovered);
    self::assertArrayHasKey('xb.drupal.announce', $discovered);
    self::assertArrayHasKey('xb.once', $discovered);
    self::assertArrayHasKey('xb.drupal.jquery.position', $discovered);
    self::assertArrayHasKey('xb.tabbable', $discovered);

    $claro_path = $this->container->get(ExtensionPathResolver::class)->getPath('theme', 'claro');
    $dialog = $discovered['xb.drupal.dialog'];
    // XB's dialog library should include dependencies from the admin theme's
    // libraries extend.
    // @see claro.info.yml
    self::assertContains('claro/claro.drupal.dialog', $dialog['dependencies']);
    self::assertContains('claro/ajax', $dialog['dependencies']);
    self::assertContains('claro/progress', $dialog['dependencies']);
    // Existing dependency.
    self::assertContains('core/drupalSettings', $dialog['dependencies']);
    // XB specific versions.
    self::assertContains('experience_builder/xb.jquery', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.internal.jquery_ui', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.drupal', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.drupalSettings', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.drupal.displace', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.once', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.drupal.jquery.position', $dialog['dependencies']);
    self::assertContains('experience_builder/xb.tabbable', $dialog['dependencies']);

    $ajax = $discovered['xb.drupal.ajax'];
    // XB's drupal ajax should include CSS from the admin theme's overrides.
    // @see claro.info.yml
    self::assertContains(\sprintf('%s/css/components/ajax-progress.module.css', $claro_path), \array_column($ajax['css'], 'data'));
    // XB specific versions of dependencies.
    self::assertContains('experience_builder/xb.once', $ajax['dependencies']);
    self::assertContains('experience_builder/xb.tabbable', $ajax['dependencies']);
    self::assertContains('experience_builder/xb.drupal.progress', $ajax['dependencies']);
    self::assertContains('experience_builder/xb.loadjs', $ajax['dependencies']);
    self::assertContains('experience_builder/xb.drupal.announce', $ajax['dependencies']);
    // Olivero brings in a dependency on core/drupal.message for drupal.ajax but
    // we want to make sure that is removed.
    self::assertNotContains('core/drupal.message', $ajax['dependencies']);
    // But the JS should still be present.
    self::assertContains('core/misc/message.js', \array_column($ajax['js'], 'data'));
    $claro_libraries = \file_get_contents(\sprintf('%s/%s/claro.libraries.yml', $this->root, $claro_path));
    self::assertNotFalse($claro_libraries);
    $parsed = Yaml::decode($claro_libraries);

    $group_css_ids = [
      'component' => CSS_COMPONENT,
      'base' => CSS_BASE,
      'layout' => CSS_LAYOUT,
      'state' => CSS_STATE,
      'theme' => CSS_THEME,
    ];
    self::assertArrayHasKey('global-styling', $parsed);
    self::assertArrayHasKey('css', $parsed['global-styling']);
    foreach ($parsed['global-styling']['css'] as $group_id => $group) {
      $expected = \array_map(static fn (string|int $path) => \sprintf('./%s/%s', $claro_path, $path), \array_keys($group));
      $group_items = \array_filter($discovered['xb.scoped.admin.css']['css'], static fn(array $item) => $item['weight'] === $group_css_ids[$group_id]);
      $actual = \array_column($group_items, 'data');
      self::assertEquals($expected, $actual);
    }

    $announce = $discovered['xb.drupal.announce'];
    self::assertContains('experience_builder/xb.drupal.debounce', $announce['dependencies']);
  }

}
