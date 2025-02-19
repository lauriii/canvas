<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests SingleDirectoryComponent.
 *
 * @covers \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent
 * @group experience_builder
 */
final class SingleDirectoryComponentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'file',
    'image',
    'link',
    'options',
    'system',
  ];

  public function testRewriteExampleUrl(): void {
    $plugin = \Drupal::service(ComponentPluginManager::class)->createInstance('experience_builder:image');
    $component = SingleDirectoryComponent::createConfigEntity($plugin);
    $source = $component->getComponentSource();
    self::assertInstanceOf(SingleDirectoryComponent::class, $source);

    // Assert that existing files are rewritten to include the module path.
    self::assertStringEndsWith('/experience_builder/components/image/600x400.png', $source->rewriteExampleUrl('600x400.png'));
    self::assertStringEndsWith('/experience_builder/components/image/600x400.png', $source->rewriteExampleUrl('/600x400.png'));
    self::assertStringEndsWith('/experience_builder/tests/fixtures/600x400.png', $source->rewriteExampleUrl('../../tests/fixtures/600x400.png'));

    // Assert that non-existing links have a leading slash but do not include the module nor SDC path.
    $url = $source->rewriteExampleUrl('test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString('experience_builder/components', $url);

    // Assert that non-existing links with a leading slash are not doubled.
    $url = $source->rewriteExampleUrl('/test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString('//', $url);

    // Assert that full URLs are left alone.
    self::assertSame('https://www.example.com/', $source->rewriteExampleUrl('https://www.example.com/'));
  }

}
