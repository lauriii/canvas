<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Config;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\canvas\Entity\Component;
use Drupal\Tests\canvas\Functional\FunctionalTestBase;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @covers \Drupal\canvas\Entity\Component::onDependencyRemoval
 *
 * @group canvas
 */
class ComponentOnDependencyRemovalTest extends FunctionalTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'test_theme_child';

  public function switchDefaultTheme(bool $withUninstall): void {
    $theme_installer = $this->container->get(ThemeInstallerInterface::class);
    $theme_installer->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();
    if ($withUninstall) {
      $theme_installer->uninstall(['test_theme_child']);
    }
  }

  public function testComponentDeletedOnThemeUninstallIfUnused(): void {
    $this->assertInstanceOf(Component::class, Component::load('sdc.test_theme_child.test-child'));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component is gone as expected.
    $this->assertNull(Component::load('sdc.test_theme_child.test-child'));
  }

  public function testComponentUsesFallbackOnThemeUninstallIfUsedInContent(): void {
    $page = Page::create([
      'title' => 'My non-empty page',
      'components' => [
        [
          'uuid' => '02b766f7-0edc-4359-98bb-3f489e878330',
          'component_id' => 'sdc.test_theme_child.test-child',
          'inputs' => [
            'title' => 'This component is used.',
          ],
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    $page->save();

    // Before: one Component version, only content entity usages.
    $component_before = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_before);
    self::assertCount(1, $component_before->getVersions());
    self::assertTrue(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::All));
    self::assertFalse(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::AutoSave));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component has a new fallback version as expected.
    $component_after = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_after);
    self::assertCount(2, $component_after->getVersions());
    $this->assertSame(Fallback::PLUGIN_ID, $component_after->getLoadedVersion());
  }

  public function testComponentUsesFallbackOnThemeUninstallIfUsedInAutoSave(): void {
    $page = Page::create(['title' => 'My empty page']);
    self::assertCount(0, $page->validate());
    $page->save();

    // After saving, use the Component in the tree, validate, and auto-save.
    $page->setComponentTree([
      [
        'uuid' => '02b766f7-0edc-4359-98bb-3f489e878330',
        'component_id' => 'sdc.test_theme_child.test-child',
        'inputs' => [
          'title' => 'This component is used.',
        ],
      ],
    ]);
    self::assertCount(0, $page->validate());
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $auto_save */
    $auto_save = \Drupal::service(AutoSaveManager::class);
    $auto_save->saveEntity($page);

    // Before: one Component version, only auto-save usages.
    $component_before = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_before);
    self::assertCount(1, $component_before->getVersions());
    self::assertFalse(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::All));
    self::assertTrue(\Drupal::service(ComponentAudit::class)->hasUsages($component_before, RevisionAuditEnum::AutoSave));

    // Install a different theme, set as default, and uninstall our previous theme.
    $this->switchDefaultTheme(TRUE);

    // The component has a new fallback version as expected.
    $component_after = Component::load('sdc.test_theme_child.test-child');
    $this->assertInstanceOf(Component::class, $component_after);
    self::assertCount(2, $component_after->getVersions());
    $this->assertSame(Fallback::PLUGIN_ID, $component_after->getLoadedVersion());
  }

}
