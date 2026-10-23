<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore Hola Título nuevo Otro título

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\canvas\Controller\ApiTranslationControllers;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\EventSubscriber\ForkedTranslationEditRequestSubscriber;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
#[CoversClass(ForkedTranslationEditRequestSubscriber::class)]
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
   * The translation whose update access ::pageAccess() forbids, if any.
   */
  private ?string $denyUpdateLangcode = NULL;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
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
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
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
   * Implements hook_ENTITY_TYPE_access() for canvas_page.
   */
  #[Hook('canvas_page_access')]
  public function pageAccess(Page $page, string $operation, AccountInterface $account): AccessResultInterface {
    return AccessResult::forbiddenIf($operation === 'update' && $page->language()->getId() === $this->denyUpdateLangcode)
      ->setCacheMaxAge(0);
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
    $this->container->get(BundleTranslationSettingsInterface::class)
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
    $component = $this->container->get(EntityTypeManagerInterface::class)
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
      ['language' => $this->container->get(LanguageManagerInterface::class)->getLanguage($langcode)],
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
  private function stageDefaultTranslationDraft(Page $page, string $title): void {
    $auto_save = $this->container->get(AutoSaveManager::class);
    \assert($auto_save instanceof AutoSaveManager);
    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage(Page::ENTITY_TYPE_ID);
    $page_id = $page->id();
    \assert($page_id !== NULL);
    $stored = $storage->loadUnchanged($page_id);
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
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));
    $draft = $auto_save->getAutoSaveEntityForPreview($stored->getTranslation('es'));
    self::assertFalse($draft->isEmpty());
    $draft_entity = $draft->entity;
    self::assertInstanceOf(ContentEntityInterface::class, $draft_entity);
    self::assertTrue(ComponentTreeTranslationFork::isForkedTranslation($draft_entity));
    // The fork seed is the translation's current symmetric state: only the
    // flag flipped, the draft keeps the same tree and the translation's own
    // translated input values.
    \assert($draft_entity instanceof Page);
    $draft_tree = $draft_entity->getComponentTree();
    self::assertCount(1, $draft_tree);
    self::assertSame(
      'Hola A (es)',
      $draft_tree->getComponentTreeItemByUuid(self::UUID_A)?->getInputs()['text'] ?? NULL,
    );

    // Forking an already-forked translation is an idempotent no-op.
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $draft = $auto_save->getAutoSaveEntityForPreview($stored->getTranslation('es'));
    self::assertFalse($draft->isEmpty());
    $repeat_entity = $draft->entity;
    self::assertInstanceOf(ContentEntityInterface::class, $repeat_entity);
    self::assertTrue(ComponentTreeTranslationFork::isForkedTranslation($repeat_entity));

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
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));

    // Fork again and publish: the fork state persists to storage. The publish
    // list only shows default-translation entries (whole-entity publish
    // grouping, see https://drupal.org/i/3591703), so stage a default-language
    // edit alongside; publishing it pulls the sibling fork draft along.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::includeSiblingTranslationAutoSaves()
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    // The fork is still draft-only: publishing below is what persists it.
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));
    $this->stageDefaultTranslationDraft($page, 'English title updated');
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertTrue(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));

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
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($draft_es));
    self::assertNull($draft_es->getComponentTree()->getComponentTreeItemByUuid(self::UUID_B));
    self::assertSame('Hola A (es)', $draft_es->getComponentTree()->getComponentTreeItemByUuid(self::UUID_A)?->getInputs()['text'] ?? NULL);

    // Unforking a not-forked translation is an idempotent no-op resync: 204,
    // and the draft still matches the default translation's tree.
    $response = $this->request(Request::create($this->forkUrl($page, 'es', unfork: TRUE), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

    // Publish the unfork; Spanish is symmetric again in storage.
    $this->stageDefaultTranslationDraft($page, 'English title updated again');
    $response = $this->makePublishAllRequest();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));
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
   * Tests requesting a language the page has no translation for.
   *
   * Entity upcasting resolves through the language fallback chain, so
   * without the controller's guard this would silently fork a different,
   * existing translation.
   */
  public function testForkUnknownTranslationRejected(): void {
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es', 'de' => 'de'])
      ->save();
    \Drupal::service('kernel')->rebuildContainer();
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
    $page = $this->createTranslatedPage();

    $response = $this->request(Request::create($this->forkUrl($page, 'de'), 'POST'));
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());

    // Nothing was forked and no draft was created for any translation.
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));
    self::assertTrue($this->container->get(AutoSaveManager::class)->getAutoSaveEntity($stored)->isEmpty());
  }

  /**
   * The layout URL for the translation named by `?canvas_langcode=`.
   */
  private static function editorLayoutUrl(Page $page, string $langcode): string {
    return Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ], ['query' => [ForkedTranslationEditRequestSubscriber::QUERY_KEY => $langcode]])->toString();
  }

  /**
   * Saves a translation's layout through the editor's whole-layout POST.
   *
   * Round-trips the layout GET response, as the editor does, with the main
   * content region emptied and the title changed.
   */
  private function postEmptiedLayout(Page $page, string $langcode, string $title): Response {
    $url = self::editorLayoutUrl($page, $langcode);
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $json = \json_decode((string) $response->getContent(), TRUE);
    \assert(\is_array($json));
    $layout = $json['layout'];
    $layout[0]['components'] = [];
    $request = Request::create($url, 'POST', content: \json_encode([
      'layout' => $layout,
      'model' => [],
      'entity_form_fields' => ['title[0][value]' => $title] + $json['entity_form_fields'],
      'autoSaves' => $json['autoSaves'],
      'clientInstanceId' => 'test-client',
    ], JSON_THROW_ON_ERROR));
    $request->headers->set('Content-Type', 'application/json');
    return $this->request($request);
  }

  /**
   * Tests editing a forked translation's layout through the editor API.
   */
  public function testEditForkedTranslation(): void {
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
    $page = $this->createTranslatedPage();
    $auto_save = $this->container->get(AutoSaveManager::class);
    \assert($auto_save instanceof AutoSaveManager);

    // Symmetric translations are read-only in the editor.
    try {
      $this->request(Request::create(self::editorLayoutUrl($page, 'es')));
      $this->fail('Expected access denied for a symmetric translation.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertSame('Only forked translations can be edited in the Canvas editor.', $e->getMessage());
    }

    // Fork Spanish: the fork lives in the draft, which is enough to edit it.
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

    // The layout and page data are the Spanish translation's.
    $response = $this->request(Request::create(self::editorLayoutUrl($page, 'es')));
    $json = \json_decode((string) $response->getContent(), TRUE);
    \assert(\is_array($json));
    self::assertSame('Spanish title', $json['entity_form_fields']['title[0][value]']);
    self::assertStringContainsString('Hola A (es)', \json_encode($json['model'], JSON_THROW_ON_ERROR));
    // Naming the default translation is the same as not naming one.
    $response = $this->request(Request::create(self::editorLayoutUrl($page, 'en')));
    $json = \json_decode((string) $response->getContent(), TRUE);
    \assert(\is_array($json));
    self::assertSame('English title', $json['entity_form_fields']['title[0][value]']);

    // A structural edit and a page data edit land in the Spanish draft only.
    $response = $this->postEmptiedLayout($page, 'es', 'Título nuevo');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    $es_draft = $auto_save->getAutoSaveEntity($stored->getTranslation('es'))->entity;
    \assert($es_draft instanceof Page);
    self::assertSame('Título nuevo', $es_draft->label());
    self::assertCount(0, $es_draft->getComponentTree());
    self::assertTrue($auto_save->getAutoSaveEntity($stored)->isEmpty());
    self::assertSame('English title', $stored->label());
    self::assertCount(1, $stored->getComponentTree());

    // A language without a translation is never resolved to another one.
    $this->expectException(NotFoundHttpException::class);
    $this->request(Request::create(self::editorLayoutUrl($page, 'de')));
  }

  /**
   * Tests that forked translation drafts publish and discard independently.
   */
  public function testForkedDraftsPublishAndDiscardIndependently(): void {
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    $page = $this->createTranslatedPage();
    $auto_save = $this->container->get(AutoSaveManager::class);
    \assert($auto_save instanceof AutoSaveManager);
    $es_key = Page::ENTITY_TYPE_ID . ':' . $page->id() . ':es';
    $en_key = Page::ENTITY_TYPE_ID . ':' . $page->id() . ':en';

    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $response = $this->postEmptiedLayout($page, 'es', 'Título nuevo');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->stageDefaultTranslationDraft($page, 'English title updated');

    // Both drafts are listed, the forked one labelled with its language.
    $pending = $this->getAutoSaveStatesFromServer();
    self::assertEqualsCanonicalizing([$en_key, $es_key], \array_keys($pending));
    self::assertSame('Título nuevo (Spanish)', $pending[$es_key]['label']);

    // Publishing the forked draft leaves the default translation's pending.
    $response = $this->makePublishAllRequest([$es_key => $pending[$es_key]]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    $es = $stored->getTranslation('es');
    \assert($es instanceof Page);
    self::assertTrue(ComponentTreeTranslationFork::isForkedTranslation($es));
    self::assertSame('Título nuevo', $es->label());
    self::assertCount(0, $es->getComponentTree());
    self::assertSame('English title', $stored->label());
    self::assertCount(1, $stored->getComponentTree());
    self::assertSame([$en_key], \array_keys($this->getAutoSaveStatesFromServer()));

    // Discarding the forked draft leaves the default translation's pending.
    $response = $this->postEmptiedLayout($page, 'es', 'Otro título');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $response = $this->request(Request::create($this->discardUrl($page, 'es'), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame([$en_key], \array_keys($this->getAutoSaveStatesFromServer()));

    // Discarding the default translation's draft leaves a forked draft.
    $response = $this->postEmptiedLayout($page, 'es', 'Otro título');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $response = $this->request(Request::create($this->discardUrl($page), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame([$es_key], \array_keys($this->getAutoSaveStatesFromServer()));
  }

  /**
   * The discard URL, for the translation named by `?canvas_langcode=`.
   *
   * Generated in the default language: URL generation would otherwise prefix
   * the language negotiated by the previous request.
   */
  private function discardUrl(Page $page, ?string $langcode = NULL): string {
    return Url::fromRoute('canvas.api.auto-save.delete', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ], [
      'language' => $this->container->get(LanguageManagerInterface::class)->getDefaultLanguage(),
      'query' => $langcode === NULL ? [] : [ForkedTranslationEditRequestSubscriber::QUERY_KEY => $langcode],
    ])->toString();
  }

  /**
   * Tests that route access is checked against the named translation.
   */
  public function testEditChecksNamedTranslationAccess(): void {
    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);
    $page = $this->createTranslatedPage();
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    $editor_url = static fn (string $prefix_langcode, string $langcode): string => Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ], [
      'language' => $language_manager->getLanguage($prefix_langcode),
      'query' => [ForkedTranslationEditRequestSubscriber::QUERY_KEY => $langcode],
    ])->toString();

    // The router checks the negotiated (Spanish) translation, but the request
    // names the English one.
    $this->denyUpdateLangcode = 'en';
    try {
      $this->request(Request::create($editor_url('es', 'en')));
      $this->fail('Expected access denied for the named default translation.');
    }
    catch (AccessDeniedHttpException) {
    }

    // The router checks the negotiated (English) translation, but the request
    // names the forked Spanish one.
    $this->denyUpdateLangcode = 'es';
    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create($editor_url('en', 'es')));
  }

  /**
   * Tests that a pending unfork is edited and discarded like a symmetric draft.
   */
  public function testPendingUnfork(): void {
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    $page = $this->createTranslatedPage();
    $es_key = Page::ENTITY_TYPE_ID . ':' . $page->id() . ':es';
    $en_key = Page::ENTITY_TYPE_ID . ':' . $page->id() . ':en';
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $pending = $this->getAutoSaveStatesFromServer();
    $response = $this->makePublishAllRequest([$es_key => $pending[$es_key]]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $response = $this->request(Request::create($this->forkUrl($page, 'es', unfork: TRUE), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

    // The pending unfork is listed on its own while the default translation
    // has no draft, and is no longer editable in the editor.
    self::assertSame([$es_key], \array_keys($this->getAutoSaveStatesFromServer()));
    try {
      $this->request(Request::create(self::editorLayoutUrl($page, 'es')));
      $this->fail('Expected access denied for a pending unfork.');
    }
    catch (AccessDeniedHttpException $e) {
      self::assertSame('Only forked translations can be edited in the Canvas editor.', $e->getMessage());
    }

    // With a default translation draft, it is listed under that draft and
    // discarded with it.
    $this->stageDefaultTranslationDraft($page, 'English title updated');
    self::assertSame([$en_key], \array_keys($this->getAutoSaveStatesFromServer()));
    $response = $this->request(Request::create($this->discardUrl($page), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame([], $this->getAutoSaveStatesFromServer());

    // Discarding a standalone pending unfork leaves the translation forked.
    $response = $this->request(Request::create($this->forkUrl($page, 'es', unfork: TRUE), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $response = $this->request(Request::create($this->discardUrl($page, 'es'), 'DELETE'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame([], $this->getAutoSaveStatesFromServer());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertTrue(ComponentTreeTranslationFork::isForkedTranslation($stored->getTranslation('es')));
  }

  /**
   * Tests that publishing a forked draft keeps newer shared field values.
   */
  public function testForkedDraftPublishKeepsSharedFields(): void {
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
    $page = $this->createTranslatedPage();
    $es_key = Page::ENTITY_TYPE_ID . ':' . $page->id() . ':es';
    $response = $this->request(Request::create($this->forkUrl($page, 'es'), 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    $response = $this->postEmptiedLayout($page, 'es', 'Título nuevo');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

    // The page template, a field shared by all translations, changes after
    // the Spanish draft was taken.
    PageVariant::create([
      'id' => 'landing',
      'label' => 'Landing',
      'component_tree' => [
        [
          'uuid' => self::UUID_B,
          'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
          'component_version' => $this->getComponentVersion(Marker::PAGE_CONTENT_COMPONENT_ID),
          'inputs' => [],
        ],
      ],
    ])->save();
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertFalse($stored->getFieldDefinition('page_variant')?->isTranslatable());
    $stored->set('page_variant', 'landing')->save();

    $pending = $this->getAutoSaveStatesFromServer();
    $response = $this->makePublishAllRequest([$es_key => $pending[$es_key]]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = Page::load($page->id());
    \assert($stored instanceof Page);
    self::assertSame('Título nuevo', $stored->getTranslation('es')->label());
    self::assertSame('landing', $stored->get('page_variant')->value);
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
