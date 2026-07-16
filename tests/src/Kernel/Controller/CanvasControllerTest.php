<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\CanvasUiAssertionsTrait;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Drupal Canvas UI mount for various entity types.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class CanvasControllerTest extends CanvasKernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use CanvasUiAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'entity_test',
    'canvas_entity_test',
    'node',
    'language',
    ...self::PAGE_TEST_MODULES,
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['node']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node_type');
    $this->installEntitySchema('node');

    NodeType::create([
      'name' => 'Amazing article',
      'type' => 'article',
    ])->save();
    $field_storage = FieldStorageConfig::create([
      'type' => 'component_tree',
      'entity_type' => 'node',
      'field_name' => 'field_canvas_tree',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
    ])->save();
  }

  /**
   * Asserts that cache contexts match the expected values, sorted.
   *
   * @param \Drupal\Core\Render\HtmlResponse $response
   *   The response to check.
   */
  private static function assertCanvasControllerCacheContexts(HtmlResponse $response): void {
    $expected_contexts = [
      'user.permissions',
      'languages:language_interface',
      'theme',
    ];
    $actual_contexts = $response->getCacheableMetadata()->getCacheContexts();
    sort($expected_contexts);
    sort($actual_contexts);
    self::assertSame($expected_contexts, $actual_contexts);
  }

  /**
   * Tests controller output when adding or editing an entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param array $permissions
   *   The permissions.
   * @param array $values
   *   The values.
   * @param null|string $expected_exception_message
   *   Consider removing in https://www.drupal.org/i/3498525.
   */
  #[DataProvider('entityData')]
  public function testController(string $entity_type, array $permissions, array $values, ?string $expected_exception_message): void {
    $this->installEntitySchema($entity_type);

    $this->setUpCurrentUser([], $permissions);

    $storage = $this->container->get('entity_type.manager')->getStorage($entity_type);
    $sut = $storage->create($values);
    $sut->save();

    $edit_url = Url::fromRoute('canvas.boot.entity', [
      'entity_type' => $entity_type,
      'entity' => $sut->id(),
    ])->toString();
    self::assertEquals("/canvas/editor/$entity_type/{$sut->id()}", $edit_url);

    if ($expected_exception_message) {
      $this->expectException(CacheableAccessDeniedHttpException::class);
      $this->expectExceptionMessage($expected_exception_message);
    }

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($edit_url));

    self::assertCanvasControllerCacheContexts($response);
    self::assertSame([
      'config:canvas.settings',
      'config:system.site',
      'test_create_access_cache_tag',
      'entity_field_info',
      'entity_bundles',
      'entity_types',
      'config:configurable_language_list',
      // The permitted text formats and their editors ship with the boot
      // settings, so format and editor configuration changes invalidate the
      // editor page.
      // @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
      'config:filter_format_list',
      'config:editor_list',
      'config:filter.format.canvas_html_block',
      'config:editor.editor.canvas_html_block',
      'config:filter.format.canvas_html_inline',
      'config:editor.editor.canvas_html_inline',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());

    $this->assertCanvasMount();
  }

  public static function entityData(): array {
    return [
      'page' => [
        Page::ENTITY_TYPE_ID,
        [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION],
        [
          'title' => 'Test page',
          'description' => 'This is a test page.',
          'components' => [],
        ],
        NULL,
      ],
      'entity_test' => [
        'entity_test',
        ['administer entity_test content', 'access content'],
        [
          'name' => 'Test entity',
        ],
        'Requires >=1 content entity type with a Canvas field that can be created or edited.',
      ],
    ];
  }

  /**
   * Tests controller exposed permissions.
   *
   * @param array $permissions
   *   The permissions.
   * @param array $expectedPermissionFlags
   *   The expected flags.
   */
  #[DataProvider('permissionsData')]
  public function testControllerExposedPermissions(array $permissions, array $expectedPermissionFlags): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->setUpCurrentUser([], $permissions);

    $canvas_url = Url::fromRoute('canvas.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/canvas", $canvas_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($canvas_url));

    $this->assertSame($expectedPermissionFlags, $this->drupalSettings['canvas']['permissions']);
    self::assertCanvasControllerCacheContexts($response);
    self::assertSame([
      'config:canvas.settings',
      'config:system.site',
      'test_create_access_cache_tag',
      'entity_field_info',
      'entity_bundles',
      'entity_types',
      'config:configurable_language_list',
      // The permitted text formats and their editors ship with the boot
      // settings, so format and editor configuration changes invalidate the
      // editor page.
      // @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
      'config:filter_format_list',
      'config:editor_list',
      'config:filter.format.canvas_html_block',
      'config:editor.editor.canvas_html_block',
      'config:filter.format.canvas_html_inline',
      'config:editor.editor.canvas_html_inline',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
  }

  public static function permissionsData(): array {
    // @see \Drupal\canvas\Entity\PageAccessControlHandler
    $page_permissions = [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
    ];

    return [
      [
        [
          ...$page_permissions,
        ],
        [
          'globalRegions' => FALSE,
          'patterns' => FALSE,
          'brandKit' => FALSE,
          'codeComponents' => FALSE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
          'folders' => FALSE,
          'configureLanguages' => FALSE,
        ],
      ],
      [
        [
          ...$page_permissions,
          JavaScriptComponent::ADMIN_PERMISSION,
          AutoSaveManager::PUBLISH_PERMISSION,
        ],
        [
          'globalRegions' => FALSE,
          'patterns' => FALSE,
          'brandKit' => FALSE,
          'codeComponents' => TRUE,
          'contentTemplates' => FALSE,
          'publishChanges' => TRUE,
          'folders' => FALSE,
          'configureLanguages' => FALSE,
        ],
      ],
      [
        [
          ...$page_permissions,
          Pattern::ADMIN_PERMISSION,
          PageRegion::ADMIN_PERMISSION,
        ],
        [
          'globalRegions' => TRUE,
          'patterns' => TRUE,
          'brandKit' => FALSE,
          'codeComponents' => FALSE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
          'folders' => FALSE,
          'configureLanguages' => FALSE,
        ],
      ],
      [
        [
          ...$page_permissions,
          Pattern::ADMIN_PERMISSION,
          PageRegion::ADMIN_PERMISSION,
          JavaScriptComponent::ADMIN_PERMISSION,
        ],
        [
          'globalRegions' => TRUE,
          'patterns' => TRUE,
          'brandKit' => FALSE,
          'codeComponents' => TRUE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
          'folders' => FALSE,
          'configureLanguages' => FALSE,
        ],
      ],
      [
        [
          ...$page_permissions,
          Pattern::ADMIN_PERMISSION,
          PageRegion::ADMIN_PERMISSION,
          JavaScriptComponent::ADMIN_PERMISSION,
          ContentTemplate::ADMIN_PERMISSION,
          AutoSaveManager::PUBLISH_PERMISSION,
          Folder::ADMIN_PERMISSION,
          'administer languages',
        ],
        [
          'globalRegions' => TRUE,
          'patterns' => TRUE,
          'brandKit' => FALSE,
          'codeComponents' => TRUE,
          'contentTemplates' => TRUE,
          'publishChanges' => TRUE,
          'folders' => TRUE,
          'configureLanguages' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Tests controller exposed content entity create operations.
   *
   * @param array $permissions
   *   The permissions.
   * @param array $expectedCreateOperations
   *   The expected create operations array.
   */
  #[DataProvider('createOperationsData')]
  public function testControllerExposedContentEntityCreateOperations(array $permissions, array $expectedCreateOperations): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->setUpCurrentUser([], $permissions);

    $canvas_url = Url::fromRoute('canvas.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/canvas", $canvas_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($canvas_url));

    $this->assertSame($expectedCreateOperations, $this->drupalSettings['canvas']['contentEntityCreateOperations']);
    self::assertCanvasControllerCacheContexts($response);
    self::assertSame([
      'config:canvas.settings',
      'config:system.site',
      'test_create_access_cache_tag',
      'entity_field_info',
      'entity_bundles',
      'entity_types',
      'config:configurable_language_list',
      // The permitted text formats and their editors ship with the boot
      // settings, so format and editor configuration changes invalidate the
      // editor page.
      // @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
      'config:filter_format_list',
      'config:editor_list',
      'config:filter.format.canvas_html_block',
      'config:editor.editor.canvas_html_block',
      'config:filter.format.canvas_html_inline',
      'config:editor.editor.canvas_html_inline',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
  }

  public static function createOperationsData(): array {
    return [
      [
        [
          'access content',
          Page::CREATE_PERMISSION,
        ],
        [
          'canvas_page' => [
            'canvas_page' => 'Page',
          ],
        ],
      ],
      [
        [
          'access content',
          Page::CREATE_PERMISSION,
          'create article content',
        ],
        [
          'canvas_page' => [
            'canvas_page' => 'Page',
          ],
          'node' => [
            'article' => 'Amazing article',
          ],
        ],
      ],
    ];
  }

  /**
   * Tests controller feature flags.
   *
   * @param array $modules
   *   The modules to enable.
   * @param array $expectedFeatureFlags
   *   The expected feature flags values.
   */
  #[DataProvider('featureFlagsData')]
  public function testControllerExposedFeatureFlags(array $modules, array $expectedFeatureFlags): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $permissions = [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
    ];
    if (!empty($modules)) {
      $this->enableModules($modules);
    }

    $this->setUpCurrentUser([], $permissions);

    $canvas_url = Url::fromRoute('canvas.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/canvas", $canvas_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($canvas_url));

    foreach ($expectedFeatureFlags as $featureFlag => $featureFlagValue) {
      $this->assertSame($featureFlagValue, $this->drupalSettings['canvas'][$featureFlag]);
    }

    self::assertCanvasControllerCacheContexts($response);
    self::assertSame([
      'config:canvas.settings',
      'config:system.site',
      'test_create_access_cache_tag',
      'entity_field_info',
      'entity_bundles',
      'entity_types',
      'config:configurable_language_list',
      // The permitted text formats and their editors ship with the boot
      // settings, so format and editor configuration changes invalidate the
      // editor page.
      // @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
      'config:filter_format_list',
      'config:editor_list',
      'config:filter.format.canvas_html_block',
      'config:editor.editor.canvas_html_block',
      'config:filter.format.canvas_html_inline',
      'config:editor.editor.canvas_html_inline',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());

  }

  /**
   * Tests the exposed native prop form settings.
   *
   * @see \Drupal\canvas\Controller\CanvasController
   */
  public function testControllerExposedPropFormsSettings(): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->setUpCurrentUser([], [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
      Page::DELETE_PERMISSION,
    ]);

    $canvas_url = Url::fromRoute('canvas.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/canvas", $canvas_url);

    // CanvasKernelTestBase::setUp() imports the module's default config, so
    // the shipped canvas.settings defaults apply: native prop forms enabled,
    // no widgets disabled.
    // The text formats the current user may use, with their editor plugin
    // ids, ship with the boot settings so formatted text props resolve to a
    // native widget (or the escape hatch) without a request.
    // @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
    $expected_text_formats = [
      [
        'id' => 'canvas_html_block',
        'label' => 'Drupal Canvas — Block HTML (locked)',
        'editor' => 'ckeditor5',
      ],
      [
        'id' => 'canvas_html_inline',
        'label' => 'Drupal Canvas — Inline HTML (locked)',
        'editor' => 'ckeditor5',
      ],
    ];
    $this->request(Request::create($canvas_url));
    $this->assertSame([
      'native' => TRUE,
      'disabledWidgets' => [],
      'textFormats' => $expected_text_formats,
    ], $this->drupalSettings['canvas']['propForms']);

    // Flipping the kill switch and disabling a widget must be reflected in the
    // exposed settings.
    $this->config('canvas.settings')
      ->set('prop_forms.native', FALSE)
      ->set('prop_forms.disabled_widgets', ['string_textfield'])
      ->save();
    $this->request(Request::create($canvas_url));
    $this->assertSame([
      'native' => FALSE,
      'disabledWidgets' => ['string_textfield'],
      'textFormats' => $expected_text_formats,
    ], $this->drupalSettings['canvas']['propForms']);
  }

  /**
   * Tests access to the page extension route and the exposed settings.
   */
  public function testPageExtensionRouteAccess(): void {
    $this->enableModules(['canvas_test_extension_page']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // The canvas_test_page extension declares the 'access content'
    // permission.
    $this->setUpCurrentUser([], [
      'access content',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $response = $this->request(Request::create('/canvas/app/canvas_test_page'));
    self::assertSame(200, $response->getStatusCode());
    $this->assertCanvasMount();
    $extensions_by_id = \array_column($this->drupalSettings['canvas']['pageExtensions'], NULL, 'id');
    // This user can edit in Canvas and has 'access content', so both the
    // extension that declares 'access content' and the one declaring no
    // permissions are exposed.
    self::assertArrayHasKey('canvas_test_page', $extensions_by_id);
    self::assertArrayHasKey('canvas_test_page_no_permissions', $extensions_by_id);
    self::assertSame('/canvas/app/canvas_test_page', $extensions_by_id['canvas_test_page']['url']);

    // Deep links into the extension's own client-side routes must survive a
    // full page load.
    $response = $this->request(Request::create('/canvas/app/canvas_test_page/reports/weekly'));
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests that inaccessible page extensions are not exposed in settings.
   */
  public function testPageExtensionsFilteredByPermission(): void {
    $this->enableModules(['canvas_test_extension_page']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // This user can edit in Canvas but lacks the 'access content' permission the
    // canvas_test_page extension declares.
    $this->setUpCurrentUser([], [
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $this->request(Request::create('/canvas'));
    $ids = \array_column($this->drupalSettings['canvas']['pageExtensions'], 'id');
    // The extension that declares 'access content' is filtered out, but the
    // extension that declares no permissions remains available to anyone who
    // can edit in Canvas.
    self::assertNotContains('canvas_test_page', $ids);
    self::assertContains('canvas_test_page_no_permissions', $ids);
  }

  /**
   * Tests a no-permission page extension is reachable by Canvas editors.
   */
  public function testPageExtensionWithoutPermissionsAccessibleToCanvasEditors(): void {
    $this->enableModules(['canvas_test_extension_page']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // A user who can edit in Canvas (passes _canvas_ui_access) but holds no
    // other permissions can load a page extension that declares none.
    $this->setUpCurrentUser([], [
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $response = $this->request(Request::create('/canvas/app/canvas_test_page_no_permissions'));
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Tests a no-permission page extension still requires Canvas edit access.
   */
  public function testPageExtensionWithoutPermissionsRequiresCanvasEditAccess(): void {
    $this->enableModules(['canvas_test_extension_page']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // A user who cannot edit in Canvas (no _canvas_ui_access) is denied even
    // though the extension declares no permissions: the route's base gate still
    // requires Canvas edit access, so it is not open to everyone.
    $this->setUpCurrentUser([], ['access content']);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->request(Request::create('/canvas/app/canvas_test_page_no_permissions'));
  }

  /**
   * Tests page extension route access denial.
   *
   * @param array $permissions
   *   The permissions.
   * @param string $path
   *   The path to request.
   * @param string $expected_exception_message
   *   The expected access denial reason.
   */
  #[DataProvider('pageExtensionAccessDeniedData')]
  public function testPageExtensionRouteAccessDenied(array $permissions, string $path, string $expected_exception_message): void {
    $this->enableModules(['canvas_test_extension', 'canvas_test_extension_page']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->setUpCurrentUser([], $permissions);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage($expected_exception_message);
    $this->request(Request::create($path));
  }

  public static function pageExtensionAccessDeniedData(): array {
    $canvas_ui_permissions = [
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ];
    return [
      'missing declared permission' => [
        $canvas_ui_permissions,
        '/canvas/app/canvas_test_page',
        "The 'access content' permission is required.",
      ],
      'not a page extension' => [
        ['access content', ...$canvas_ui_permissions],
        '/canvas/app/canvas_test_extension',
        "There is no page extension with the ID 'canvas_test_extension'.",
      ],
      'unknown extension' => [
        ['access content', ...$canvas_ui_permissions],
        '/canvas/app/does_not_exist',
        "There is no page extension with the ID 'does_not_exist'.",
      ],
    ];
  }

  public static function featureFlagsData(): \Generator {
    yield 'none' => [
      [],
      [
        'extensionsAvailable' => FALSE,
        'aiExtensionAvailable' => FALSE,
        'personalizationExtensionAvailable' => FALSE,
      ],
    ];
    yield 'ai' => [
      ['canvas_ai'],
      [
        'aiExtensionAvailable' => TRUE,
        'personalizationExtensionAvailable' => FALSE,
      ],
    ];
    yield 'personalization' => [
      ['canvas_personalization'],
      [
        'extensionsAvailable' => FALSE,
        'aiExtensionAvailable' => FALSE,
        'personalizationExtensionAvailable' => TRUE,
      ],
    ];
    yield 'extensions available' => [
      ['canvas_test_extension'],
      [
        'extensionsAvailable' => TRUE,
      ],
    ];
    yield 'all' => [
      ['canvas_ai', 'canvas_personalization'],
      [
        'aiExtensionAvailable' => TRUE,
        'personalizationExtensionAvailable' => TRUE,
      ],
    ];
  }

}
