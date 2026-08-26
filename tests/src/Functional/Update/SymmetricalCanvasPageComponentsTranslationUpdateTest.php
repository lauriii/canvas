<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests enforcing symmetrical translation of the `components` base field.
 *
 * @legacy-covers \canvas_post_update_0022_enforce_symmetrical_canvas_page_components_translation
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
final class SymmetricalCanvasPageComponentsTranslationUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  private const SYMMETRICAL = [
    'inputs' => 'inputs',
    'tree' => '0',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Without content_translation, the update must do nothing.
   *
   * When content_translation gets installed later, the base field override is
   * created by hook_modules_installed() instead.
   *
   * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
   */
  public function testWithoutContentTranslation(): void {
    $this->runUpdates();

    self::assertNull(BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'));
  }

  /**
   * An existing override with unsupported settings must be fixed.
   *
   * For example: sites that marked the component tree translatable while the
   * `canvas_dev_translation` feature flag module existed.
   */
  public function testExistingOverrideWithUnsupportedSettings(): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    $override = self::loadOrCreateComponentsOverride();
    $override->setThirdPartySetting('content_translation', 'translation_sync', [
      'inputs' => 'inputs',
      'tree' => 'tree',
    ]);
    $override->save();

    $this->runUpdates();

    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);
    self::assertSame(self::SYMMETRICAL, $override->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($override);
  }

  /**
   * An existing override that already has the right settings is kept as-is.
   */
  public function testExistingOverrideWithValidSettings(): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    $override = self::loadOrCreateComponentsOverride();
    $override->setThirdPartySetting('content_translation', 'translation_sync', self::SYMMETRICAL);
    $override->save();

    $this->runUpdates();

    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);
    self::assertSame(self::SYMMETRICAL, $override->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($override);
  }

  /**
   * With content_translation but no override, the update must create one.
   *
   * Sites that installed content_translation before this Canvas release never
   * got the override created by hook_modules_installed().
   */
  public function testMissingOverride(): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    // Installing content_translation just created the override via
    // hook_modules_installed(). Delete it to simulate a site that installed
    // content_translation before that hook existed.
    BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components')?->delete();

    $this->runUpdates();

    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);
    self::assertSame(self::SYMMETRICAL, $override->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($override);
  }

  private static function loadOrCreateComponentsOverride(): BaseFieldOverride {
    $components = \Drupal::service(EntityFieldManagerInterface::class)
      ->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID)['components'];
    \assert($components instanceof BaseFieldDefinition);
    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components')
      ?? BaseFieldOverride::createFromBaseFieldDefinition($components, Page::ENTITY_TYPE_ID);
    \assert($override instanceof BaseFieldOverride);
    return $override;
  }

}
