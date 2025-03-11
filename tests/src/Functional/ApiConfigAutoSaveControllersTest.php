<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\AssetLibrary;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\XbAssetInterface;
use Drupal\Tests\experience_builder\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;

/**
 * Tests the details of auto-saving config entities, NOT the "live" version.
 *
 * @covers \Drupal\experience_builder\Controller\ApiConfigAutoSaveControllers
 */
final class ApiConfigAutoSaveControllersTest extends HttpApiTestBase {

  use TestDataUtilitiesTrait;
  use ContribStrictConfigSchemaTestTrait;
  use AutoSaveManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected readonly UserInterface $httpApiUser;

  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser([
      'access administration pages',
      'administer code components',
    ]);
    assert($user instanceof UserInterface);
    $this->httpApiUser = $user;
  }

  public static function providerTest(): array {
    return [
      JavaScriptComponent::ENTITY_TYPE_ID => [
        JavaScriptComponent::ENTITY_TYPE_ID,
        [
          'machineName' => 'test',
          'name' => 'Test',
          'status' => FALSE,
          'required' => [
            'string',
            'integer',
          ],
          'props' => [
            'string' => [
              'type' => 'string',
              'title' => 'Title',
              'examples' => ['Press', 'Submit now'],
            ],
            'boolean' => [
              'type' => 'boolean',
              'title' => 'Truth',
              'examples' => [TRUE, FALSE],
            ],
            'integer' => [
              'type' => 'integer',
              'title' => 'Integer',
              'examples' => [23, 10, 2024],
            ],
            'number' => [
              'type' => 'number',
              'title' => 'Number',
              'examples' => [3.14],
            ],
          ],
          'slots' => [],
          'source_code_js' => 'console.log("Test")',
          'source_code_css' => '.test { display: none; }',
          'compiled_js' => 'console.log("Test")',
          'compiled_css' => '.test{display:none;}',
        ],
        ['compiled_js'],
        ['compiled_css'],
      ],
      AssetLibrary::ENTITY_TYPE_ID => [
        AssetLibrary::ENTITY_TYPE_ID,
        [
          'id' => 'global',
          'label' => 'I am an asset!',
          'css' => [
            'compiled' => '.test{display:none;}',
            'original' => '.test { display: none; }',
          ],
          'js' => [
            'compiled' => 'console.log("Test")',
            'original' => 'console.log("Test")',
          ],
        ],
        ['js', 'compiled'],
        ['css', 'compiled'],
      ],
    ];
  }

  /**
   * @dataProvider providerTest
   */
  public function test(
    string $entity_type_id,
    array $initial_entity,
    array $compiled_js_path_in_normalization,
    array $compiled_css_path_in_normalization,
  ): void {
    if ($entity_type_id === AssetLibrary::ENTITY_TYPE_ID) {
      // Delete the library created during install.
      $library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
      \assert($library instanceof AssetLibrary);
      $library->delete();
    }
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $definition = $entity_type_manager->getDefinition($entity_type_id);
    $id_key = $definition->getKey('id');
    assert(!empty($initial_entity[$id_key]));
    $entity_id = $initial_entity[$id_key];
    $base = rtrim(base_path(), '/');
    $post_url = Url::fromUri("base:/xb/api/config/$entity_type_id");
    $auto_save_url = Url::fromUri("base:/xb/api/config/auto-save/$entity_type_id/$entity_id");
    $js_url = Url::fromRoute('experience_builder.api.config.auto-save.get.js', [
      'xb_config_entity_type_id' => $entity_type_id,
      'xb_config_entity' => $entity_id,
    ]);
    $css_url = Url::fromRoute('experience_builder.api.config.auto-save.get.css', [
      'xb_config_entity_type_id' => $entity_type_id,
      'xb_config_entity' => $entity_id,
    ]);

    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $this->drupalLogin($this->httpApiUser);

    // GETting the auto-save state for a config entity when that entity does not yet exist: 404.
    $auto_save_data = $this->assertExpectedResponse('GET', $auto_save_url, $request_options, 404, NULL, NULL, 'UNCACHEABLE (request policy)', 'UNCACHEABLE (no cacheability)');
    $this->assertSame([], $auto_save_data);

    // CSS and JS draft endpoints should be 404 as well.
    $this->drupalGet($js_url);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($css_url);
    $this->assertSession()->statusCodeEquals(404);

    $request_options[RequestOptions::BODY] = self::encodeXBData($initial_entity);
    $this->assertExpectedResponse('POST', $post_url, $request_options, 201, NULL, NULL, NULL, NULL, [
      'Location' => [
        "$base/xb/api/config/$entity_type_id/{$entity_id}",
      ],
    ]);
    $original_entity = $storage->load($entity_id);
    \assert($original_entity instanceof XbAssetInterface);
    assert($original_entity !== NULL);
    $original_entity_array = $original_entity->toArray();
    assert(is_array($original_entity_array));

    // Now the entity exists, these should 307 to the non-draft version.
    // Prevent redirects so we can test that we indeed receive a 307.
    $this->maximumMetaRefreshCount = 0;
    $url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($url_generator instanceof FileUrlGeneratorInterface);
    $driver = $this->getSession()->getDriver();
    \assert($driver instanceof BrowserKitDriver);
    $driver->getClient()->followRedirects(FALSE);
    $this->drupalGet($js_url);
    $this->assertSession()->statusCodeEquals(307);
    $this->assertSession()->responseHeaderEquals('location', $url_generator->generateString($original_entity->getJsPath()));
    $this->drupalGet($css_url);
    $this->assertSession()->statusCodeEquals(307);
    $this->assertSession()->responseHeaderEquals('location', $url_generator->generateString($original_entity->getCssPath()));
    // Allow redirects again.
    $driver->getClient()->followRedirects();
    $this->maximumMetaRefreshCount = NULL;

    // Anonymously: 403.
    $this->drupalLogout();
    $body = $this->assertExpectedResponse('GET', $auto_save_url, [], 403, ['user.permissions'], ['4xx-response', 'config:user.role.anonymous', 'http_response'], 'MISS', NULL);
    $this->assertSame([
      'errors' => [
        "The 'access administration pages' permission is required.",
      ],
    ], $body);
    $body = $this->assertExpectedResponse('PATCH', $auto_save_url, [], 403, NULL, NULL, NULL, NULL);
    $this->assertSame([
      'errors' => [
        "The 'access administration pages' permission is required.",
      ],
    ], $body);

    // Assert auto-saving works for:
    // 1. The given *valid* entity values.
    $this->drupalLogin($this->httpApiUser);
    $this->performAutoSave($initial_entity, $entity_type_id, $entity_id);

    // Assert that draft endpoints can be fetched.
    // Draft CSS/JS should not be available to unprivileged users.
    $this->drupalLogout();
    $this->drupalGet($js_url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($css_url);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->httpApiUser);
    $this->drupalGet($js_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'text/javascript; charset=utf-8');
    self::assertEquals(NestedArray::getValue($initial_entity, $compiled_js_path_in_normalization), $this->getSession()->getPage()->getContent());

    $this->drupalGet($css_url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'text/css; charset=utf-8');
    self::assertEquals(NestedArray::getValue($initial_entity, $compiled_css_path_in_normalization), $this->getSession()->getPage()->getContent());

    $this->assertSingleConfigAutoSaveList($original_entity, $initial_entity, $this->httpApiUser);
    // 2. The given *valid* entity values, with a garbage key-value pair added.
    $this->performAutoSave($initial_entity + ['new_key' => 'new_value'], $entity_type_id, $entity_id);
    $this->assertSingleConfigAutoSaveList($original_entity, $initial_entity + ['new_key' => 'new_value'], $this->httpApiUser);
    // 3. For an empty array (which obviously would not be a valid entity).
    $this->performAutoSave([], $entity_type_id, $entity_id);
    $this->assertSingleConfigAutoSaveList($original_entity, [], $this->httpApiUser);
    // 4. For pure garbage key-value pairs.
    $this->performAutoSave(['any_key' => ['any' => 'value']], $entity_type_id, $entity_id);
    $this->assertSingleConfigAutoSaveList($original_entity, ['any_key' => ['any' => 'value']], $this->httpApiUser);

    $this->assertSame($original_entity_array, $storage->loadUnchanged($entity_id)?->toArray(), 'The original entity was not changed by the auto-save.');
  }

  private function assertSingleConfigAutoSaveList(EntityInterface $entity, array $auto_save_data, UserInterface $user): void {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];
    $expected_list = [
      $entity->getEntityTypeId() . ':' . $entity->id() => [
        'owner' => [
          'name' => $user->getDisplayName(),
          'avatar' => NULL,
          'uri' => $user->toUrl()->toString(),
          'id' => (int) $user->id(),
        ],
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),

        'data_hash' => self::generateAutoSaveHash($auto_save_data),
        'langcode' => NULL,
        'label' => $entity->label(),
      ],
    ];
    $body = $this->assertExpectedResponse('GET', Url::fromUri("base:/xb/api/autosaves/pending"), $request_options, 200, ['user.permissions'], ['config:user.settings', 'experience_builder__autosave', 'http_response', 'user:2'], 'UNCACHEABLE (request policy)', 'MISS');
    $id = array_keys($expected_list)[0];
    assert(isset($body[$id]['updated']));
    unset($body[$id]['updated']);
    $this->assertSame($expected_list, $body);
  }

}
