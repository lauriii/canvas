<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the `components` base field override creation on module install.
 *
 * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
 */
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class SymmetricalTranslationsModulesInstalledTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Installing content_translation after canvas must create the override.
   */
  public function testInstallingContentTranslationCreatesOverride(): void {
    self::assertNull(BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'));

    $this->container->get(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    $override = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('base_field_override')
      ->load(\sprintf('%s.%s.components', Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID));
    self::assertInstanceOf(BaseFieldOverride::class, $override);
    self::assertSame([
      'inputs' => 'inputs',
      'tree' => '0',
    ], $override->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($override);
  }

}
