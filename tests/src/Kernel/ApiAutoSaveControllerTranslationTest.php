<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\Page;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests translation-related behavior of the auto-save API.
 *
 * @see \Drupal\canvas\Controller\ApiAutoSaveController
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiAutoSaveController::class)]
#[Group('canvas')]
final class ApiAutoSaveControllerTranslationTest extends CanvasKernelTestBase {

  use RequestTrait;
  use AutoSaveRequestTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'language',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['language']);
  }

  /**
   * Tests that publishing via the auto-save API preserves non-default language translations.
   *
   * @legacy-covers ::post
   */
  public function testPublishingPreservesNonDefaultLanguageTranslations(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);
    $page_storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);

    $page = Page::create([
      'title' => 'Original English Title',
      'status' => TRUE,
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    self::assertSame(SAVED_NEW, $page->save());
    $page_id = $page->id();
    self::assertNotNull($page_id);

    $page->addTranslation('es', [
      'title' => 'Spanish Title',
      'status' => TRUE,
      'components' => [],
    ])->save();

    $page = $page_storage->loadUnchanged($page_id);
    \assert($page instanceof Page);
    self::assertTrue($page->hasTranslation('es'), 'Spanish translation should exist before publishing.');
    self::assertSame('Spanish Title', $page->getTranslation('es')->label());

    $page->set('title', 'Updated English Title');
    $autoSave->saveEntity($page);

    $auto_save_data = $this->getAutoSaveStatesFromServer();
    $page_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertArrayHasKey($page_key, $auto_save_data, 'Auto-save entry should be visible to the current user.');

    $response = $this->makePublishAllRequest([
      $page_key => $auto_save_data[$page_key],
    ]);
    self::assertEquals(Response::HTTP_OK, $response->getStatusCode());

    $published_page = $page_storage->loadUnchanged($page_id);
    \assert($published_page instanceof Page);

    self::assertSame(
      'Updated English Title',
      $published_page->label(),
      'English title should be updated after publishing.'
    );

    self::assertTrue(
      $published_page->hasTranslation('es'),
      'Spanish translation should be preserved after publishing the English auto-save via the auto-save API.'
    );
    self::assertSame(
      'Spanish Title',
      $published_page->getTranslation('es')->label(),
      'Spanish translation title should be unchanged after publishing the English auto-save.'
    );
  }

}
