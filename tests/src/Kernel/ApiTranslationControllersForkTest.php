<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore Hola

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Controller\ApiTranslationControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the translation fork/unfork HTTP API endpoints.
 *
 * Fork and unfork operate on auto-save drafts: nothing changes in storage
 * until the draft is published, and discarding the draft reverts the fork
 * state. The endpoints ship in the canvas_dev_translation feature-flag
 * module.
 *
 * @see canvas_dev_translation.routing.yml
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiTranslationControllers::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ApiTranslationControllersForkTest extends CanvasKernelTestBase {

  use RequestTrait;
  use AutoSaveRequestTestTrait;
  use ConstraintViolationsTestTrait;
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

  private const string UUID_A = '11111111-1111-4111-8111-111111111111';
  private const string UUID_B = '22222222-2222-4222-8222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_dev_translation',
    'canvas_test_sdc',
    'language',
    'content_translation',
    'field',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    // In order to reflect the changes for a multilingual site in the container
    // we have to rebuild it, so URL-prefix language negotiation is active for
    // the fork/unfork requests below (the `?language=` upcast convention).
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();
    \Drupal::service('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    // Kernel tests do not run hook_modules_installed(), so enforce the
    // symmetric translation_sync setting the way a real site gets it.
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
    $this->installEntitySchema('node');
    $this->installConfig(['node']);
    $this->generateComponentConfig();
  }

  /**
   * Creates a published English page with a Spanish translation.
   */
  private function createTranslatedPage(): Page {
    $version = $this->getComponentVersion('sdc.canvas_test_sdc.heading');
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'path' => ['alias' => '/english-page'],
      'components' => [
        [
          'uuid' => self::UUID_A,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $version,
          'inputs' => ['text' => 'Hello A (en)', 'element' => 'h1'],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $es = $page->addTranslation('es', [
      'title' => 'Spanish title',
      'status' => TRUE,
      'path' => ['alias' => '/spanish-page'],
      'components' => $page->get('components')->getValue(),
    ]);
    \assert($es instanceof Page);
    $item = $es->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A);
    self::assertNotNull($item);
    $item->setInput(['text' => 'Hola A (es)', 'element' => 'h1']);
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($es)
      ->setSource('en');
    $es->save();
    $loaded = Page::load($page->id());
    \assert($loaded instanceof Page);
    return $loaded;
  }

  /**
   * The active version of the given component.
   */
  private function getComponentVersion(string $component_id): string {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('component')
      ->load($component_id);
    self::assertNotNull($component);
    return $component->getActiveVersion();
  }

  /**
   * Builds the fork/unfork URL for a page + langcode.
   */
  private function forkUrl(Page $page, string $langcode, bool $unfork = FALSE): string {
    return Url::fromRoute(
      $unfork ? 'canvas.api.content.translation.unfork' : 'canvas.api.content.translation.fork',
      ['canvas_page' => $page->id()],
      ['language' => $this->container->get('language_manager')->getLanguage($langcode)],
    )->toString();
  }

  /**
   * Fetches the layout GET response data for a page.
   */
  private function getLayoutData(Page $page): array {
    $url = Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ])->toString();
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $json = \json_decode((string) $response->getContent(), TRUE);
    \assert(\is_array($json));
    return $json;
  }

  /**
   * Stages a default-translation auto-save draft with a changed title.
   *
   * Translation-only drafts are hidden from the publish list until
   * per-language publish lands, so tests publish fork drafts by grouping them
   * with a default-translation draft, matching the current product behavior.
   */
  private function stageDefaultTranslationDraft(string $title): void {
    $auto_save = $this->container->get(AutoSaveManager::class);
    \assert($auto_save instanceof AutoSaveManager);
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $stored = $storage->loadUnchanged(1);
    \assert($stored instanceof Page);
    $draft = $auto_save->getAutoSaveEntityForPreview($stored);
    $default = $draft->isEmpty() ? $stored : $draft->entity;
    \assert($default instanceof Page);
    $default = $default->getUntranslated();
    $default->set('title', $title);
    $auto_save->saveEntity($default);
  }

  /**
   * Tests the full fork → publish → unfork → publish lifecycle.
   */
  public function testForkLifecycle(): void {
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    $page = $this->createTranslatedPage();
    $auto_save = $this->container->get(AutoSaveManager::class);
    \assert($auto_save instanceof AutoSaveManager);

    // Initially: not forked, layout advertises a fork link for Spanish and no
    // fork-related link for the default language.
    $layout = $this->getLayoutData($page);
    self::assertSame([], $layout['translations']['forked']);
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_FORK, $layout['translations']['links']['es'] ?? []);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_UNFORK, $layout['translations']['links']['es'] ?? []);

    // Fork Spanish. The URL carries the language via the standard URL-prefix
    // negotiation, mirroring how the Canvas UI receives these links.
    self::assertStringContainsString('/es/', $this->forkUrl($page, 'es'));
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

    // The fork lives in the auto-save draft, not in storage.
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($stored->getTranslation('es')));
    $draft = $auto_save->getAutoSaveEntityForPreview($stored->getTranslation('es'));
    self::assertFalse($draft->isEmpty());
    self::assertTrue(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($draft->entity));

    // The layout response is draft-aware: Spanish is forked, with an unfork
    // link.
    $layout = $this->getLayoutData($page);
    self::assertSame(['es'], $layout['translations']['forked']);
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_UNFORK, $layout['translations']['links']['es'] ?? []);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_FORK, $layout['translations']['links']['es'] ?? []);

    // Discarding the draft reverts the fork.
    $auto_save->delete($stored->getTranslation('es'));
    $layout = $this->getLayoutData($page);
    self::assertSame([], $layout['translations']['forked']);
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($stored->getTranslation('es')));

    // Fork again and publish: the fork state persists to storage. The publish
    // list only shows default-translation entries (whole-entity publish
    // grouping, see https://drupal.org/i/3591703), so stage a default-language
    // edit alongside; publishing it pulls the sibling fork draft along.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::includeSiblingTranslationAutoSaves()
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $this->stageDefaultTranslationDraft('English title updated');
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertTrue(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($stored->getTranslation('es')));

    // Diverge the forked Spanish tree in storage: add a component only there.
    $es = $stored->getTranslation('es');
    $version = $this->getComponentVersion('sdc.canvas_test_sdc.heading');
    $es->set('components', [
      ...$es->get('components')->getValue(),
      [
        'uuid' => self::UUID_B,
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => $version,
        'inputs' => ['text' => 'Solo B (es)', 'element' => 'h2'],
      ],
    ]);
    $es->save();
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertNotNull($stored->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));
    self::assertNull($stored->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));

    // Unfork: the draft re-syncs from the default translation, dropping the
    // fork-only component but keeping the translated input for the surviving
    // one. Storage is untouched until publish.
    $response = $this->request(Request::create($this->forkUrl($page, 'es', unfork: TRUE), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertNotNull($stored->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));
    $draft = $auto_save->getAutoSaveEntityForPreview($stored->getTranslation('es'));
    self::assertFalse($draft->isEmpty());
    $draft_es = $draft->entity;
    \assert($draft_es instanceof Page);
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($draft_es));
    self::assertNull($draft_es->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));
    self::assertSame('Hola A (es)', $draft_es->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)?->getInputs()['text'] ?? NULL);

    // Publish the unfork; Spanish is symmetric again in storage.
    $this->stageDefaultTranslationDraft('English title updated again');
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation($stored->getTranslation('es')));
    self::assertNull($stored->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));
    self::assertSame('Hola A (es)', $stored->getTranslation('es')->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)?->getInputs()['text'] ?? NULL);
  }

  /**
   * Tests that the default translation cannot be forked or unforked.
   */
  public function testForkDefaultTranslationRejected(): void {
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
    $page = $this->createTranslatedPage();

    $response = $this->request(Request::create($this->forkUrl($page, 'en'), 'POST'));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

    $response = $this->request(Request::create($this->forkUrl($page, 'en', unfork: TRUE), 'DELETE'));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($stored)->isEmpty());
  }

  /**
   * Tests that forking requires entity update access.
   */
  public function testForkRequiresUpdateAccess(): void {
    // Create the page as a privileged user, then switch to an account without
    // update access: the route's _entity_access requirement must reject it.
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
    $page = $this->createTranslatedPage();
    $this->setUpCurrentUser(permissions: ['access administration pages']);
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
  }

}
