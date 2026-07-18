<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the ApiContentControllers::patch() method.
*/
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentControllers::class)]
#[CoversMethod(ApiContentControllers::class, 'patch')]
class ApiContentControllersPatchTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
    'language',
  ];

  private const string URL = '/canvas/api/v0/content/canvas_page/%s';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installConfig(['system', 'field', 'filter', 'path_alias', 'language']);

    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION, Page::EDIT_PERMISSION]);

    $component_source_manager = \Drupal::service(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_manager->generateComponents();
  }

  /**
   * Sets up French and German plus URL-prefix negotiation for both.
   *
   * The container rebuild makes the prefixes active, so a language-prefixed
   * request upcasts the matching translation — mirroring how the Canvas UI
   * addresses translations.
   */
  private function setUpLanguages(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr', 'de' => 'de'])
      ->save();
    \Drupal::service('kernel')->rebuildContainer();
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
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
   * PATCHes a langcode change and returns the response.
   */
  private function patchLangcode(Page $page, string $langcode, string $url_prefix = ''): Response {
    return $this->request(Request::create(
      $url_prefix . \sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode(['langcode' => $langcode], JSON_THROW_ON_ERROR),
    ));
  }

  /**
   * Tests patch() returns a page with its component tree.
   *
   * @legacy-covers \Drupal\canvas\Controller\ApiContentControllers::patch
   */
  #[DataProvider('providerPatch')]
  public function testPatch(array $page_contents, array $expected_response_contents): void {
    $page = Page::create([
      'title' => 'Initial title',
      'status' => FALSE,
      'path' => ['alias' => '/this-is-the-old-path'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $request = Request::create(\sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => $page_contents['title'],
        'status' => TRUE,
        'path' => $page_contents['alias'],
        'components' => $page_contents['components'],
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->request($request);
    // The response of a PATCH request shouldn't be cacheable.
    \assert($response instanceof JsonResponse && !$response instanceof CacheableJsonResponse);

    $data = $this->decodeResponse($response);

    // Versioned public APIs need to be strict: this means asserting
    // that we get all the expected info, but also NO extra additions.
    // So we use `assertSame` in the full response contents.
    $this->assertSame(
      ['id' => (int) $page->id(), 'uuid' => $page->uuid()] + $expected_response_contents,
      $data
    );
  }

  /**
   * Tests clearing an explicit page variant selection.
   */
  public function testPatchClearsPageVariant(): void {
    $marker = Component::load(Marker::PAGE_CONTENT_COMPONENT_ID);
    self::assertInstanceOf(ComponentInterface::class, $marker);
    PageVariant::create([
      'id' => 'marketing',
      'label' => 'Marketing',
      'status' => TRUE,
      'component_tree' => [[
        'uuid' => '14b2e2b7-5e05-42e2-9f6e-2ffdbb37df35',
        'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
        'component_version' => $marker->getActiveVersion(),
        'inputs' => [],
      ],
      ],
    ])->save();
    $page = Page::create([
      'title' => 'Marketing page',
      'status' => FALSE,
      'path' => ['alias' => '/marketing'],
      'page_variant' => 'marketing',
      'components' => [],
    ]);
    $page->save();

    $response = $this->request(Request::create(
      \sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Marketing page',
        'status' => FALSE,
        'path' => '/marketing',
        'pageVariant' => NULL,
        'components' => [],
      ], JSON_THROW_ON_ERROR),
    ));

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertNull($this->decodeResponse($response)['pageVariant']);
    self::assertSame('', Page::load($page->id())?->get('page_variant')->getString());
  }

  public static function providerPatch(): \Generator {
    yield "Empty tree" => [
      [
        'title' => 'Test Page',
        'alias' => '/test-page',
        'status' => TRUE,
        'components' => [],
      ],
      [
        'title' => 'Test Page',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/test-page',
        'internalPath' => '/page/1',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [],
        'description' => '',
        'pageVariant' => NULL,
        'links' => [
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/1',
        ],
      ],
    ];

    yield "A component tree with slots (ensuring decoded inputs)" => [
      [
        'title' => 'Page with components',
        'alias' => '/components-page',
        'status' => TRUE,
        'components' => [
          [
            'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'inputs' => ['heading' => 'Welcome'],
            'parent_uuid' => NULL,
            'slot' => NULL,
            'label' => NULL,
          ],
          [
            'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'inputs' => ['heading' => 'Nested'],
            'parent_uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'slot' => 'the_body',
            'label' => NULL,
          ],
        ],
      ],
      [
        'title' => 'Page with components',
        'status' => TRUE,
        'isNew' => FALSE,
        'hasUnsavedStatusChange' => FALSE,
        'path' => '/components-page',
        'internalPath' => '/page/1',
        'autoSaveLabel' => NULL,
        'autoSavePath' => NULL,
        'components' => [
          [
            'parent_uuid' => NULL,
            'slot' => NULL,
            'uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'inputs' => ['heading' => 'Welcome'],
            'label' => NULL,
            'inputs_resolved' => ['heading' => 'Welcome'],
          ],
          [
            'parent_uuid' => '09365c2d-1ee1-47fd-b5a3-7e4f34866186',
            'slot' => 'the_body',
            'uuid' => 'af5fc5ab-1457-4258-880f-541a69c0110b',
            'component_id' => 'sdc.canvas_test_sdc.props-slots',
            'component_version' => '0e79e884426a53ae',
            'inputs' => ['heading' => 'Nested'],
            'label' => NULL,
            'inputs_resolved' => ['heading' => 'Nested'],
          ],
        ],
        'description' => '',
        'pageVariant' => NULL,
        'links' => [
          CanvasUriDefinitions::LINK_REL_UNPUBLISH => '/canvas/api/v0/content/auto-save/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_EDIT => '/canvas/editor/canvas_page/1',
          CanvasUriDefinitions::LINK_REL_DUPLICATE => '/canvas/api/v0/content/canvas_page',
          CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => '/canvas/editor/canvas_page/1',
        ],
      ],
    ];
  }

  /**
   * Tests that PATCHing a path alias results in exactly one alias being set.
   */
  #[DataProvider('providerPatchPathAlias')]
  public function testPatchPathAlias(array $initial_path): void {
    $page = Page::create([
      'title' => 'Initial title',
      'status' => TRUE,
      'path' => $initial_path,
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $alias_storage = \Drupal::entityTypeManager()
      ->getStorage('path_alias');
    $internal_path = ['path' => '/page/' . $page->id()];

    $this->assertCount(
      !empty($initial_path) ? 1 : 0,
      $alias_storage->loadByProperties($internal_path),
    );

    $this->request(Request::create(
      \sprintf(self::URL, $page->id()),
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: \json_encode([
        'title' => 'Initial title',
        'status' => TRUE,
        'path' => '/new-alias',
        'components' => [],
      ], JSON_THROW_ON_ERROR),
    ));

    $path_aliases = $alias_storage->loadByProperties($internal_path);
    $this->assertCount(1, $path_aliases);
    $this->assertSame('/new-alias', reset($path_aliases)->getAlias());
  }

  public static function providerPatchPathAlias(): \Generator {
    yield 'With a pre-existing alias' => [
      ['alias' => '/old-alias'],
      1,
    ];
    yield 'Without a pre-existing alias' => [
      [],
      0,
    ];
  }

  /**
   * Tests changing a page's original language via PATCH `langcode`.
   */
  public function testPatchLangcode(): void {
    $this->setUpLanguages();
    $this->setLanguageAlterable(TRUE);

    $page = Page::create([
      'title' => 'English original',
      'status' => TRUE,
      'path' => ['alias' => '/english-original'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    // A successful retag: the original language changes and field data is
    // retagged in place.
    $response = $this->patchLangcode($page, 'de');
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $reloaded = $this->reload($page);
    $this->assertSame('de', $reloaded->language()->getId());
    $this->assertSame('de', $reloaded->get('title')->getLangcode());
    $this->assertSame('English original', $reloaded->label());
  }

  /**
   * Tests that sibling translations survive a language change.
   */
  public function testPatchLangcodeKeepsTranslations(): void {
    $this->setUpLanguages();
    $this->setLanguageAlterable(TRUE);

    $page = Page::create([
      'title' => 'English original',
      'status' => TRUE,
      'path' => ['alias' => '/english-original'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $page->addTranslation('fr', [
      'title' => 'French translation',
      'status' => TRUE,
      'path' => ['alias' => '/french-translation'],
      'components' => $page->get('components')->getValue(),
    ]);
    $page->save();

    $response = $this->patchLangcode($page, 'de');
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $reloaded = $this->reload($page);
    $this->assertSame('de', $reloaded->language()->getId());
    $this->assertTrue($reloaded->hasTranslation('fr'));
    $this->assertSame('French translation', $reloaded->getTranslation('fr')->label());
  }

  /**
   * Tests the guards blocking a language change.
   */
  public function testPatchLangcodeGuards(): void {
    $this->setUpLanguages();
    $this->setLanguageAlterable(TRUE);

    $page = Page::create([
      'title' => 'English original',
      'status' => TRUE,
      'path' => ['alias' => '/english-original'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $page->addTranslation('fr', [
      'title' => 'French translation',
      'status' => TRUE,
      'path' => ['alias' => '/french-translation'],
      'components' => $page->get('components')->getValue(),
    ]);
    $page->save();

    // Unknown langcode: 400.
    $response = $this->patchLangcode($page, 'xx');
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame(
      ['error' => 'The provided langcode "xx" is not one of the configured languages.'],
      $this->decodeResponse($response),
    );

    // Occupied target language: 409, nothing changes.
    $response = $this->patchLangcode($page, 'fr');
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertStringContainsString('Delete that translation', $this->decodeResponse($response)['error']);
    $this->assertSame('en', $this->reload($page)->language()->getId());

    // A language-prefixed request upcasts the French translation: 422.
    $response = $this->patchLangcode($page, 'de', '/fr');
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertStringContainsString('default translation', $this->decodeResponse($response)['error']);
    $this->assertSame('en', $this->reload($page)->language()->getId());

    // Pending auto-save drafts anywhere in the translation group: 409.
    $auto_save_manager = \Drupal::service(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $draft = $this->reload($page)->getTranslation('fr');
    $draft->set('title', 'French draft');
    $auto_save_manager->saveEntity($draft);
    $response = $this->patchLangcode($page, 'de');
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertStringContainsString('Publish or discard', $this->decodeResponse($response)['error']);
    $this->assertSame('en', $this->reload($page)->language()->getId());
  }

  /**
   * Tests that `language_alterable` gates the language change.
   */
  public function testPatchLangcodeNotAlterable(): void {
    $this->setUpLanguages();
    $this->setLanguageAlterable(FALSE);

    $page = Page::create([
      'title' => 'English original',
      'status' => TRUE,
      'path' => ['alias' => '/english-original'],
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->patchLangcode($page, 'de');
  }

  /**
   * Reloads a page bypassing the static entity cache.
   */
  private function reload(Page $page): Page {
    $page_id = $page->id();
    \assert($page_id !== NULL);
    $reloaded = \Drupal::entityTypeManager()->getStorage(Page::ENTITY_TYPE_ID)->loadUnchanged($page_id);
    \assert($reloaded instanceof Page);
    return $reloaded;
  }

}
