<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Controller;

use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Kernel\Traits\PageTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\XbUiAssertionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Experience Builder UI mount for various entity types.
 *
 * @group experience_builder
 */
final class ExperienceBuilderControllerTest extends KernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;
  use XbUiAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'entity_test',
    ...self::PAGE_TEST_MODULES,
    'block',
    'node',
    // XB's dependencies (modules providing field types + widgets).
    'text',
    'datetime',
    'file',
    'image',
    'media',
    'options',
    'path',
    'link',
    'system',
    'user',
  ];

  protected function setUp(): void {
    parent::setUp();
    // Needed for date formats.
    $this->installConfig(['system']);
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
      'field_name' => 'field_xb_tree',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
    ])->save();
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
   * @param null|string $expected_logic_exception_message
   *   Consider removing in https://www.drupal.org/i/3498525.
   *
   * @dataProvider entityData
   */
  public function testController(string $entity_type, array $permissions, array $values, ?string $expected_logic_exception_message = NULL): void {
    $this->installEntitySchema($entity_type);

    $this->setUpCurrentUser([], $permissions);

    $storage = $this->container->get('entity_type.manager')->getStorage($entity_type);
    $sut = $storage->create($values);
    $sut->save();

    $edit_url = Url::fromRoute('experience_builder.boot.entity', [
      'entity_type' => $entity_type,
      'entity' => $sut->id(),
    ])->toString();
    self::assertEquals("/xb/$entity_type/{$sut->id()}", $edit_url);

    if ($expected_logic_exception_message) {
      $this->expectException(\LogicException::class);
      $this->expectExceptionMessage($expected_logic_exception_message);
    }

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($edit_url));

    self::assertSame([
      'user.permissions',
      'languages:language_interface',
      'theme',
    ], $response->getCacheableMetadata()->getCacheContexts());
    self::assertSame([
      'config:system.site',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());

    $this->assertExperienceBuilderMount($entity_type, $sut);
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
      ],
      'entity_test' => [
        'entity_test',
        ['administer entity_test content'],
        [
          'name' => 'Test entity',
        ],
        'This entity does not have an XB field!',
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
   *
   * @dataProvider permissionsData
   */
  public function testControllerExposedPermissions(array $permissions, array $expectedPermissionFlags): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->setUpCurrentUser([], $permissions);

    $xb_url = Url::fromRoute('experience_builder.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/xb", $xb_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($xb_url));

    $this->assertSame($expectedPermissionFlags, $this->drupalSettings['xb']['permissions']);
    self::assertSame([
      'user.permissions',
      'languages:language_interface',
      'theme',
    ], $response->getCacheableMetadata()->getCacheContexts());
    self::assertSame([
      'config:system.site',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
  }

  public static function permissionsData(): array {
    // @see \Drupal\experience_builder\Entity\PageAccessControlHandler
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
          'codeComponents' => FALSE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
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
          'codeComponents' => TRUE,
          'contentTemplates' => FALSE,
          'publishChanges' => TRUE,
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
          'codeComponents' => FALSE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
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
          'codeComponents' => TRUE,
          'contentTemplates' => FALSE,
          'publishChanges' => FALSE,
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
        ],
        [
          'globalRegions' => TRUE,
          'patterns' => TRUE,
          'codeComponents' => TRUE,
          'contentTemplates' => TRUE,
          'publishChanges' => TRUE,
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
   *
   * @dataProvider createOperationsData
   */
  public function testControllerExposedContentEntityCreateOperations(array $permissions, array $expectedCreateOperations): void {
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $this->setUpCurrentUser([], $permissions);

    $xb_url = Url::fromRoute('experience_builder.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/xb", $xb_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($xb_url));

    $this->assertSame($expectedCreateOperations, $this->drupalSettings['xb']['contentEntityCreateOperations']);
    self::assertSame([
      'user.permissions',
      'languages:language_interface',
      'theme',
    ], $response->getCacheableMetadata()->getCacheContexts());
    self::assertSame([
      'config:system.site',
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
          'xb_page' => [
            'xb_page' => 'Page',
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
          'xb_page' => [
            'xb_page' => 'Page',
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
   *
   * @dataProvider featureFlagsData
   */
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

    $xb_url = Url::fromRoute('experience_builder.boot.empty', [
      'entity_type' => '',
      'entity' => '',
    ])->toString();
    self::assertEquals("/xb", $xb_url);

    /** @var \Drupal\Core\Render\HtmlResponse $response */
    $response = $this->request(Request::create($xb_url));

    foreach ($expectedFeatureFlags as $featureFlag => $featureFlagValue) {
      $this->assertSame($featureFlagValue, $this->drupalSettings['xb'][$featureFlag]);
    }

    self::assertSame([
      'user.permissions',
      'languages:language_interface',
      'theme',
    ], $response->getCacheableMetadata()->getCacheContexts());
    self::assertSame([
      'config:system.site',
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());

  }

  public static function featureFlagsData(): \Generator {
    yield 'none' => [
      [],
      [
        'aiExtensionAvailable' => FALSE,
        'personalizationExtensionAvailable' => FALSE,
      ],
    ];
    yield 'ai' => [
      ['xb_ai'],
      [
        'aiExtensionAvailable' => TRUE,
        'personalizationExtensionAvailable' => FALSE,
      ],
    ];
    yield 'personalization' => [
      ['xb_personalization'],
      [
        'aiExtensionAvailable' => FALSE,
        'personalizationExtensionAvailable' => TRUE,
      ],
    ];
    yield 'all' => [
      ['xb_ai', 'xb_personalization'],
      [
        'aiExtensionAvailable' => TRUE,
        'personalizationExtensionAvailable' => TRUE,
      ],
    ];
  }

}
