<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Page;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the layout API's original-language metadata and links.
 *
 * `translations.defaultLangcode` names the entity's original language
 * explicitly, and `set-default-language` links advertise for which languages
 * the original language can be changed. Forked translations (independent
 * component trees) are still translations, so they must never gain a
 * `set-default-language` link.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiLayoutController::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ApiLayoutControllerLanguageTest extends CanvasKernelTestBase {

  use RequestTrait;
  use ContentTranslationTestTrait;
  use GenerateComponentConfigTrait;
  use UserCreationTrait;

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
    ConfigurableLanguage::createFromLangcode('de')->save();
    // In order to reflect the changes for a multilingual site in the container
    // we have to rebuild it, so URL-prefix language negotiation is active for
    // the language-prefixed requests below.
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es', 'de' => 'de'])
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
    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);
  }

  /**
   * Allows changing the original language of canvas_page entities.
   */
  private function setLanguageAlterable(bool $alterable): void {
    ContentLanguageSettings::loadByEntityTypeBundle(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID)
      ->setLanguageAlterable($alterable)
      ->save();
  }

  /**
   * Creates a published page with a translation.
   */
  private function createTranslatedPage(): Page {
    $page = Page::create([
      'title' => 'English title',
      'status' => TRUE,
      'path' => ['alias' => '/english-page'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $es = $page->addTranslation('es', [
      'title' => 'Spanish title',
      'status' => TRUE,
      'path' => ['alias' => '/spanish-page'],
      'components' => $page->get('components')->getValue(),
    ]);
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($es)
      ->setSource('en');
    $es->save();
    $loaded = Page::load($page->id());
    \assert($loaded instanceof Page);
    return $loaded;
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
   * Tests `defaultLangcode` and `set-default-language` link eligibility.
   */
  public function testDefaultLangcodeAndLinks(): void {
    $this->setLanguageAlterable(TRUE);
    $page = $this->createTranslatedPage();

    $translations = $this->getLayoutData($page)['translations'];
    self::assertSame('en', $translations['defaultLangcode']);
    self::assertSame(['en', 'es'], $translations['available']);

    // German: configurable, untranslated, not the original — eligible. The
    // link is the entity's PATCH URL.
    $de_links = $translations['links']['de'] ?? [];
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $de_links);
    self::assertStringEndsWith(
      '/canvas/api/v0/content/canvas_page/' . $page->id(),
      $de_links[CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE],
    );
    // Spanish has a translation, English is the original: neither is eligible.
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $translations['links']['es'] ?? []);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $translations['links']['en'] ?? []);

    // When the language is not alterable, no language has the link.
    $this->setLanguageAlterable(FALSE);
    $translations = $this->getLayoutData($page)['translations'];
    self::assertSame('en', $translations['defaultLangcode']);
    foreach ($translations['links'] as $language_links) {
      self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $language_links);
    }
  }

  /**
   * Tests that forked translations stay excluded from `set-default-language`.
   *
   * Composes with the asymmetric-translation-forks capability: a forked
   * translation is still a translation, so the "no existing translation in
   * the target language" eligibility rule already excludes forked siblings.
   */
  public function testForkedTranslationExcluded(): void {
    $this->setLanguageAlterable(TRUE);
    $page = $this->createTranslatedPage();

    // Fork the Spanish translation through the fork endpoint.
    $fork_url = Url::fromRoute(
      'canvas.api.content.translation.fork',
      ['canvas_page' => $page->id()],
      ['language' => $this->container->get('language_manager')->getLanguage('es')],
    )->toString();
    $response = $this->request(Request::create($fork_url, 'POST'));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

    $translations = $this->getLayoutData($page)['translations'];
    self::assertSame('en', $translations['defaultLangcode']);
    self::assertSame(['es'], $translations['forked']);
    // The forked Spanish translation carries fork-state links, never a
    // set-default-language link.
    $es_links = $translations['links']['es'] ?? [];
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_UNFORK, $es_links);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $es_links);
    // German (no translation at all) keeps its set-default-language link and
    // has no fork-state links.
    $de_links = $translations['links']['de'] ?? [];
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $de_links);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_FORK, $de_links);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_UNFORK, $de_links);
  }

  /**
   * Tests the metadata for a page whose original language is not the default.
   */
  public function testNonDefaultOriginalLanguage(): void {
    $this->setLanguageAlterable(TRUE);
    $page = Page::create([
      'title' => 'German title',
      'status' => TRUE,
      'path' => ['alias' => '/german-page'],
      'components' => [],
      'langcode' => 'de',
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $translations = $this->getLayoutData($page)['translations'];
    self::assertSame('de', $translations['defaultLangcode']);
    self::assertSame(['de'], $translations['available']);
    // The set-default-language link targets the original (German) translation,
    // so its URL carries the German URL prefix.
    $en_links = $translations['links']['en'] ?? [];
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $en_links);
    self::assertStringEndsWith(
      '/de/canvas/api/v0/content/canvas_page/' . $page->id(),
      $en_links[CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE],
    );
    self::assertArrayHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $translations['links']['es'] ?? []);
    self::assertArrayNotHasKey(CanvasUriDefinitions::LINK_REL_SET_DEFAULT_LANGUAGE, $translations['links']['de'] ?? []);
  }

}
