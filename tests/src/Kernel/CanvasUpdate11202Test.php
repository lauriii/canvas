<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Language\LanguageInterface;
use Drupal\language\Entity\ContentLanguageSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests canvas_update_11202(): canvas_page content language settings.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasUpdate11202Test extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    \Drupal::moduleHandler()->loadInclude('canvas', 'install');
  }

  /**
   * Tests that the update hook creates the settings when absent.
   */
  public function testCreatesSettingsWhenAbsent(): void {
    // The base class installs canvas's default configuration, which with the
    // Language module enabled includes the shipped `config/optional` settings.
    // The update hook targets existing sites that predate that file, so model
    // the config's absence explicitly.
    $existing = ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    if (!$existing->isNew()) {
      $existing->delete();
    }
    self::assertTrue(ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID)->isNew());

    canvas_update_11202();

    $settings = ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    self::assertFalse($settings->isNew());
    self::assertTrue($settings->isLanguageAlterable());
    self::assertSame(LanguageInterface::LANGCODE_SITE_DEFAULT, $settings->getDefaultLangcode());
  }

  /**
   * Tests that customized settings are left untouched.
   */
  public function testLeavesCustomizedSettingsUntouched(): void {
    ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID)
      ->setDefaultLangcode('en')
      ->setLanguageAlterable(FALSE)
      ->save();

    canvas_update_11202();

    $settings = ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    self::assertFalse($settings->isLanguageAlterable());
    self::assertSame('en', $settings->getDefaultLangcode());
  }

}
