<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Controller\ConflictResolutionOutcomeEnum;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\Component\Datetime\Time;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests Drupal\canvas\AutoSave\AutoSaveManager.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(AutoSaveManager::class)]
#[Group('canvas')]
class AutoSaveManagerTest extends CanvasKernelTestBase {

  use CanvasFieldCreationTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;

  private const string UUID_IN_ROOT = '78c73c1d-4988-4f9b-ad17-f7e337d40c29';

  protected static $modules = [
    'language',
    'node',
    'field',
  ];

  private static function recursiveReverseSort(array $data): array {
    // If $data is associative array reverse it, but preserve the keys.
    if (!array_is_list($data)) {
      $data = array_reverse($data, TRUE);
    }
    foreach ($data as $key => $value) {
      if (\is_array($value)) {
        $data[$key] = self::recursiveReverseSort($value);
      }
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    $container->getDefinition('datetime.time')
      ->setClass(AutoSaveManagerTestTime::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.theme')->set('default', 'stark')->save();
    // URLs are generated during some of these kernel tests. Canvas depends on
    // the `path` module, so the PathAlias entity type must be installed. URL
    // generation fails without this.
    $this->installEntitySchema('path_alias');
    $this->generateComponentConfig();
  }

  private static function convertClientData(EntityInterface $entity, array $data): EntityInterface {
    if ($entity instanceof FieldableEntityInterface) {
      $data['model'] = (array) $data['model'];
      $layout = $data['layout'];
      $content = NULL;
      foreach ($layout as $region_node) {
        $client_side_region_id = $region_node['id'];
        if ($client_side_region_id === CanvasPageVariant::MAIN_CONTENT_REGION) {
          $content = $region_node;
        }
      }
      \assert($content !== NULL);
      \Drupal::service(ClientDataToEntityConverter::class)->convert(['layout' => $content] + $data, $entity, validate: FALSE);
      return $entity;
    }
    if ($entity instanceof PageRegion) {
      $entity = $entity->forAutoSaveData($data, validate: FALSE);
      return $entity;
    }
    \assert($entity instanceof CanvasHttpApiEligibleConfigEntityInterface);
    $updated_entity = $entity::create($entity->toArray());
    $updated_entity->updateFromClientSide($data);
    return $updated_entity;
  }

  private function assertAutoSaveCreated(EntityInterface $entity, array $matching_client_data, array $updated_client_data): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $autoSaveEntity = $this->convertClientData($entity, $matching_client_data);
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());
    // Reversing the order of the data should not trigger an auto-save entry either.
    $autoSaveEntity = $this->convertClientData($entity, self::recursiveReverseSort($matching_client_data));
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());

    // Now update the entity.
    $autoSaveEntity = $this->convertClientData($entity, $updated_client_data);
    $autoSave->saveEntity($autoSaveEntity);

    self::assertFalse($autoSave->getAutoSaveEntity($entity)->isEmpty());
    $autoSaveKey = AutoSaveManager::getAutoSaveKey($entity);
    $autoSaveEntry = $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashInitial = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashInitial);

    // Reversing the order of the data should result in the exact same hash.
    $autoSaveEntity = $this->convertClientData($entity, self::recursiveReverseSort($updated_client_data));
    $autoSave->saveEntity($autoSaveEntity);
    self::assertFalse($autoSave->getAutoSaveEntity($entity)->isEmpty());
    $autoSaveEntry = $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey];
    self::assertArrayHasKey('data_hash', $autoSaveEntry);
    $hashReversedData = $autoSaveEntry['data_hash'];
    self::assertNotEmpty($hashReversedData);
    self::assertSame($hashInitial, $hashReversedData);

    if ($entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      $autoSaveStore = NULL;
      // Modifying the (config) entity `status` key does NOT result in the
      // auto-save being wiped, but in it being updated.
      $status_key = $entity->getEntityType()->getKey('status');
      if ($status_key) {
        self::assertTrue($autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey]['data'][$status_key]);
        // Capture original_hash before the status-only save.
        $autoSaveStore = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
        $hash_before_status_save = $autoSaveStore->get($autoSaveKey)[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
        $entity->disable()->save();
        self::assertFalse($autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey]['data'][$status_key]);
        // original_hash must advance to the current stored entity hash so a
        // subsequent conflict check does not produce a false positive.
        $hash_after_status_save = $autoSaveStore->get($autoSaveKey)[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
        self::assertNotSame(
          $hash_before_status_save,
          $hash_after_status_save,
          'original_hash must advance after a status-only config entity save.',
        );
        // We also have to update the original client data so that a new auto
        // save entry deletes the existing (matching) data.
        $matching_client_data[$status_key] = FALSE;
      }

      // Modifying the (config) entity `label` key does NOT result in the
      // auto-save being wiped, but in it being updated.
      $label_key = $entity->getEntityType()->getKey('label');
      if ($label_key) {
        self::assertSame($updated_client_data[$label_key], $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey]['data'][$label_key]);
        // Capture original_hash before the label-only save.
        $autoSaveStore ??= $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
        $hash_before_label_save = $autoSaveStore->get($autoSaveKey)[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
        $entity->set($label_key, 'magic 🪄')->save();
        self::assertSame('magic 🪄', $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE)[$autoSaveKey]['data'][$label_key]);
        // original_hash must advance to the current stored entity hash so a
        // subsequent conflict check does not produce a false positive.
        $hash_after_label_save = $autoSaveStore->get($autoSaveKey)[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
        self::assertNotSame(
          $hash_before_label_save,
          $hash_after_label_save,
          'original_hash must advance after a label-only config entity save.',
        );
        // We also have to update the original client data so that a new auto
        // save entry deletes the existing (matching) data.
        $matching_client_data[$label_key] = 'magic 🪄';
      }
    }

    // Resaving the initial state should delete the auto-save entry.
    $autoSaveEntity = $this->convertClientData($entity, $matching_client_data);
    $autoSave->saveEntity($autoSaveEntity);
    self::assertTrue($autoSave->getAutoSaveEntity($entity)->isEmpty());
  }

  public function testCanvasPage(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $canvas_page = Page::create([
      'title' => '5 amazing uses for old toothbrushes',
      'components' => [],
    ]);
    self::assertEntityIsValid($canvas_page);
    self::assertSame(SAVED_NEW, $canvas_page->save());

    $request = Request::create('/api/canvas/content/canvas_page/' . $canvas_page->id());
    $envelope = \Drupal::classResolver(ApiLayoutController::class)->get(request: $request, entity: $canvas_page);
    \assert($envelope instanceof PreviewEnvelope);
    $matching_client_data = \array_intersect_key(
      $envelope->additionalData,
      \array_flip(['layout', 'model', 'entity_form_fields'])
    );
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($canvas_page, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component triggers an auto-save entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      // This is intentionally missing a version AND a non-existent component to
      // confirm that auto-saves do not perform validation.
      'type' => 'sdc.canvas_test_sdc.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($canvas_page, $matching_client_data, $new_component_client_data);
  }

  /**
   * Memoizes the reconstructed auto-save entity without serializing it.
   *
   * Repeated reads returning the same object instance proves no serialize /
   * unserialize round-trip happens (which would recompute computed fields like
   * metatag's and recurse).
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveEntity()
   */
  public function testGetAutoSaveEntityCachesWithoutSerialization(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page = Page::create([
      'title' => 'Original title',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $page->set('title', 'Changed title');
    $autoSave->saveEntity($page);

    $first = $autoSave->getAutoSaveEntity($page);
    self::assertFalse($first->isEmpty());
    $second = $autoSave->getAutoSaveEntity($page);
    self::assertSame($first, $second);
    self::assertSame($first->entity, $second->entity);
  }

  /**
   * Tests that auto-saves for different Page translations are stored independently.
   *
   * Verifies that:
   * - Auto-saves for different translations use distinct keys.
   * - Saving/loading auto-saves in different languages doesn't interfere with each other
   */
  public function testPageAutoSaveTranslationBehavior(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['language']);

    // Create French language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Create the English page (default language).
    $page_en = Page::create([
      'title' => 'English page title',
      'langcode' => 'en',
      'components' => [],
    ]);
    self::assertEntityIsValid($page_en);
    self::assertSame(SAVED_NEW, $page_en->save());

    // Add French translation.
    $page_fr = $page_en->addTranslation('fr', [
      'title' => 'Titre de la page en français',
    ]);
    $page_fr->save();

    // Verify auto-save keys are different for each translation.
    $key_en = AutoSaveManager::getAutoSaveKey($page_en);
    $key_fr = AutoSaveManager::getAutoSaveKey($page_fr);
    self::assertNotEquals($key_en, $key_fr);

    // Confirm no auto-saves exist initially.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Make a change to the English page and save auto-save.
    $page_en->set('title', 'Modified English title');
    $auto_save_manager->saveEntity($page_en);

    // Verify English auto-save exists and French is unaffected.
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Verify only English auto-save is in the list.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertEquals([$key_en], \array_keys($list));
    self::assertEquals('Modified English title', $list[$key_en]['label']);

    // Make a change to the French page and save auto-save.
    $page_fr->set('title', 'This is the French title');
    $auto_save_manager->saveEntity($page_fr);

    // Verify both auto-saves exist independently.
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    // Verify both auto-saves are in the list with correct labels.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    $keys = \array_keys($list);
    asort($keys);
    self::assertEquals([$key_en, $key_fr], $keys);
    self::assertEquals('Modified English title', $list[$key_en]['label']);
    self::assertEquals('This is the French title', $list[$key_fr]['label']);

    // Verify language codes are stored correctly.
    self::assertEquals('en', $list[$key_en]['langcode']);
    self::assertEquals('fr', $list[$key_fr]['langcode']);

    // Delete the English auto-save by restoring original title.
    $page_en->set('title', 'English page title');
    $auto_save_manager->saveEntity($page_en);

    // Verify English auto-save is gone but French remains.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertEquals([$key_fr], \array_keys($list));

    // Delete the French auto-save.
    $auto_save_manager->delete($page_fr);

    // Verify all auto-saves are gone.
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());
    self::assertEmpty($auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));

    // Recreate an auto-save for each translation, then delete the entity. The
    // hook_entity_delete implementation must cascade and discard every
    // translation's auto-save, not just the default translation's, so no
    // orphaned sibling draft is left behind.
    // @see \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
    // @see \Drupal\Tests\canvas\Kernel\ComponentSource\ConfigEntitySymmetricalTranslationPropagationTestBase::testEntityDeleteDiscardsStagedOverrides()
    $page_en->set('title', 'Modified English title again');
    $auto_save_manager->saveEntity($page_en);
    $page_fr->set('title', 'Titre français à nouveau');
    $auto_save_manager->saveEntity($page_fr);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());

    $page_en->delete();
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_en)->isEmpty());
    self::assertTrue($auto_save_manager->getAutoSaveEntity($page_fr)->isEmpty());
    self::assertEmpty($auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));
  }

  public function testPageRegion(): void {
    $page_region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_IN_ROOT,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'd34b93534777207a',
          'inputs' => [
            'heading' => 'world',
          ],
        ],
      ],
    ]);
    \assert($page_region instanceof PageRegion);
    $this->assertSame(SAVED_NEW, $page_region->save());
    $page_region_matching_client_data = $page_region->getComponentTree()->getClientSideRepresentation();
    $non_matching_region_client_data = $page_region_matching_client_data;
    $non_matching_region_client_data['model'][self::UUID_IN_ROOT]['resolved']['heading'] = 'This is a different heading.';
    $this->assertAutoSaveCreated($page_region, $page_region_matching_client_data, $non_matching_region_client_data);
  }

  public function testJsComponent(): void {
    $js_component = JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'text' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['Press', 'Submit now'],
        ],
      ],
      'slots' => [
        'test-slot' => [
          'title' => 'test',
          'description' => 'Title',
          'examples' => [
            'Test 1',
            'Test 2',
          ],
        ],
      ],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '.test { display: none; }',
        'compiled' => '.test{display:none;}',
      ],
      'dataDependencies' => [],
    ]);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $js_component_matching_client_data = $js_component->normalizeForClientSide()->values;
    $js_component_matching_client_data['importedJsComponents'] = [];
    $non_matching_js_component_client_data = $js_component_matching_client_data;
    $non_matching_js_component_client_data['props']['text']['examples'][] = 'Press, or don\'t. Whatever.';
    $this->assertAutoSaveCreated($js_component, $js_component_matching_client_data, $non_matching_js_component_client_data);
  }

  public function testAssetLibrary(): void {
    $asset_library = AssetLibrary::load('global');
    \assert($asset_library instanceof AssetLibrary);
    $asset_library_matching_client_data = $asset_library->normalizeForClientSide()->values;
    $non_matching_asset_library_client_data = $asset_library_matching_client_data;
    $non_matching_asset_library_client_data['label'] = 'Slightly less boring label';
    $non_matching_asset_library_client_data['css']['original'] = $non_matching_asset_library_client_data['css']['original'] . '/**/';
    $this->assertAutoSaveCreated($asset_library, $asset_library_matching_client_data, $non_matching_asset_library_client_data);
  }

  public function testNode(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->createComponentTreeField('node', 'article', 'field_component_tree');
    $this->setUpImages();
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'status' => FALSE,
      'field_hero' => $this->referencedImage,
      'field_canvas_demo' => [],
      'body' => [
        'value' => '',
        'summary' => '',
      ],
    ]);
    self::assertEntityIsValid($node);
    $this->assertSame(SAVED_NEW, $node->save());

    $request = Request::create('/api/canvas/content/canvas_page/' . $node->id());
    $envelope = \Drupal::classResolver(ApiLayoutController::class)->get(request: $request, entity: $node);
    \assert($envelope instanceof PreviewEnvelope);
    $matching_client_data = \array_intersect_key(
      $envelope->additionalData,
      \array_flip(['layout', 'model', 'entity_form_fields'])
    );
    $new_title_client_data = $matching_client_data;
    $new_title_client_data['entity_form_fields']['title[0][value]'] = '5 MORE amazing uses for old toothbrushes';
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_title_client_data);

    // Confirm that adding a component to the node via the client also triggers an auto-save entry.
    $new_component_client_data = $matching_client_data;
    $new_component_client_data['layout'][0]['components'][] = [
      'nodeType' => 'component',
      'uuid' => 'static-image-udf7d',
      'type' => 'sdc.canvas_test_sdc.static_image',
      'slots' => [],
    ];
    $this->assertAutoSaveCreated($node, $matching_client_data, $new_component_client_data);
  }

  public function testStagedConfigUpdate(): void {
    $sut = $this->container->get(AutoSaveManager::class);
    self::assertInstanceOf(AutoSaveManager::class, $sut);
    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_change_site_name',
      'label' => 'Change the site name',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['name' => 'My awesome site'],
        ],
      ],
    ])->save();

    $list = $sut->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey('staged_config_update:canvas_change_site_name', $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My awesome site'],
      ],
    ], $list['staged_config_update:canvas_change_site_name']['data']['actions']);

    // Prove duplicated saves overwrite the previous one.
    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_change_site_name',
      'label' => 'Change the site name',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['name' => 'My SUPER AWESOME site'],
        ],
      ],
    ])->save();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey('staged_config_update:canvas_change_site_name', $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My SUPER AWESOME site'],
      ],
    ], $list['staged_config_update:canvas_change_site_name']['data']['actions']);

    StagedConfigUpdate::createFromClientSide([
      'id' => 'canvas_set_homepage',
      'label' => 'Update the front page',
      'target' => 'system.site',
      'actions' => [
        [
          'name' => 'simpleConfigUpdate',
          'input' => ['page.front' => '/home'],
        ],
      ],
    ])->save();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(2, $list);
    self::assertArrayHasKey('staged_config_update:canvas_set_homepage', $list);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['name' => 'My SUPER AWESOME site'],
      ],
    ], $list['staged_config_update:canvas_change_site_name']['data']['actions']);
    self::assertEquals([
      [
        'name' => 'simpleConfigUpdate',
        'input' => ['page.front' => '/home'],
      ],
    ], $list['staged_config_update:canvas_set_homepage']['data']['actions']);

    // On config delete, auto-saved staged config updates targeting that config
    // should be deleted. In the current state, that's everything.
    $config_manager = $this->container->get(ConfigManagerInterface::class);
    \assert($config_manager instanceof ConfigManagerInterface);
    $config_manager->getConfigFactory()->getEditable('system.site')->delete();
    $list = $sut->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertEmpty($list);
  }

  public function testComponentFormViolationsTempStore(): void {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $uuid = 'b26efbd7-f711-481c-a001-947396ed6ad3';
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(0, $violations);
    $violations->add(new ConstraintViolation(
      'Bending Hectic',
      NULL,
      [],
      NULL,
      'strange.weather',
      'Grand Illusion',
    ));
    $auto_save_manager->saveComponentInstanceFormViolations($uuid, $violations);
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(1, $violations);
    $violation = $violations[0];
    \assert($violation instanceof ConstraintViolationInterface);
    self::assertEquals('Bending Hectic', $violation->getMessage());
    self::assertEquals('strange.weather', $violation->getPropertyPath());

    $page = Page::create([
      'title' => 'Immortal Love',
      'components' => [
        [
          'uuid' => $uuid,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Cinnamon Temple',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($page);
    $auto_save_manager->delete($page);
    $violations = $auto_save_manager->getComponentInstanceFormViolations($uuid);
    self::assertCount(0, $violations);
  }

  /**
   * Tests that auto-save entries do not expire.
   *
   * Verifies that auto-save entries stored in the key-value store remain
   * accessible over extended periods of time.
   *
   * @legacy-covers ::saveEntity
   * @legacy-covers ::getAutoSaveEntity
   * @legacy-covers ::getAllAutoSaveList
   */
  public function testAutoSaveDoesNotExpire(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    // Create a page entity.
    $page = Page::create([
      'title' => 'Test page for persistence',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    // Make a change to trigger an auto-save.
    $page->set('title', 'Updated title');
    $auto_save_manager->saveEntity($page);

    // Verify the auto-save exists.
    $auto_save_key = AutoSaveManager::getAutoSaveKey($page);
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($auto_save_key, $list);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page)->isEmpty());
    self::assertEquals('Updated title', $list[$auto_save_key]['label']);

    $tempstore_expire = \Drupal::getContainer()->getParameter('tempstore.expire');
    self::assertIsInt($tempstore_expire);
    // Advance time so the tempstore has expired.
    AutoSaveManagerTestTime::$offset = $tempstore_expire + 24 * 60;

    // Verify the auto-save entry still persists after the tempstore has expired.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertCount(1, $list);
    self::assertArrayHasKey($auto_save_key, $list);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page)->isEmpty());
    self::assertEquals('Updated title', $list[$auto_save_key]['label']);
  }

  /**
   * Tests conflict resolution APIs on auto-save entries for Page entities.
   */
  public function testConflictResolutionMethods(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $page = Page::create([
      'title' => 'Conflict resolution test page',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    // No auto-save exists yet.
    self::assertNull($auto_save_manager->getUnresolvedConflictForEntity($page));
    self::assertSame(ConflictResolutionOutcomeEnum::NoAutoSaveItem, $auto_save_manager->resolveConflict($page, 'missing'));

    // Auto-save exists, but no conflict yet.
    $page->set('title', 'Draft update');
    $auto_save_manager->saveEntity($page);
    self::assertNull($auto_save_manager->getUnresolvedConflictForEntity($page));
    self::assertSame(ConflictResolutionOutcomeEnum::NoActiveConflict, $auto_save_manager->resolveConflict($page, 'still_missing'));

    // Capture H1: original_hash at initial auto-save write time, before any
    // external change.
    $key = AutoSaveManager::getAutoSaveKey($page);
    $auto_save_store = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
    $entry = $auto_save_store->get($key);
    \assert(\is_array($entry));
    $h1 = $entry[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];

    // Trigger a conflict via external save.
    $page->set('title', 'External update #1');
    $page->setNewRevision(TRUE);
    self::assertSame(SAVED_UPDATED, $page->save());
    $active_conflict = AutoSaveManager::getConflictId($page);
    self::assertSame($active_conflict, $auto_save_manager->getUnresolvedConflictForEntity($page));

    // Wrong conflict id should not resolve.
    self::assertSame(ConflictResolutionOutcomeEnum::ConflictMismatch, $auto_save_manager->resolveConflict($page, 'wrong_id'));
    self::assertSame($active_conflict, $auto_save_manager->getUnresolvedConflictForEntity($page));

    // Correct conflict id resolves it.
    self::assertSame(ConflictResolutionOutcomeEnum::Resolved, $auto_save_manager->resolveConflict($page, $active_conflict));
    self::assertNull($auto_save_manager->getUnresolvedConflictForEntity($page));

    $list_with_conflicts = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: TRUE);
    self::assertArrayNotHasKey('conflict_id', $list_with_conflicts[$key]);
    $list_without_conflicts = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE);
    self::assertArrayNotHasKey('conflict_id', $list_without_conflicts[$key]);

    // resolveConflict() advances original_hash from H1 to H2 (the current
    // stored entity hash after the external change).
    $entry = $auto_save_store->get($key);
    \assert(\is_array($entry));
    $h2 = $entry[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
    self::assertNotSame($h1, $h2);

    // A subsequent ::saveEntity() call must not re-surface the resolved
    // conflict. ::saveEntity() computes a fresh original_hash from storage,
    // which equals H2 set by ::resolveConflict(), so no mismatch is found.
    // original_hash remains H2.
    $page->set('title', 'Draft after resolution');
    $auto_save_manager->saveEntity($page);
    self::assertNull($auto_save_manager->getUnresolvedConflictForEntity($page));
    $entry = $auto_save_store->get($key);
    \assert(\is_array($entry));
    self::assertSame($h2, $entry[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);

    // A later external save creates a new unresolved conflict.
    $page->set('title', 'External update #2');
    $page->setNewRevision(TRUE);
    self::assertSame(SAVED_UPDATED, $page->save());
    $new_conflict = AutoSaveManager::getConflictId($page);
    self::assertNotSame($active_conflict, $new_conflict);
    self::assertSame($new_conflict, $auto_save_manager->getUnresolvedConflictForEntity($page));

    $list_with_conflicts = $auto_save_manager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: TRUE);
    \assert(isset($list_with_conflicts[$key]['conflict_id']));
    self::assertSame($new_conflict, $list_with_conflicts[$key]['conflict_id']);
  }

  /**
   * Tests that saveEntity() does not advance stored `original_hash` value.
   *
   * When auto-save item is updated via AutoSaveManager::saveEntity() it's
   * `original_hash` property must not change.
   * When an auto-save item has an unresolved conflict, a call to ::saveEntity()
   * must not dismiss the conflict.
   */
  public function testSaveEntityDoesNotAdvanceStoredEntityHash(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $page = Page::create([
      'title' => 'Original title',
      'components' => [],
    ]);
    self::assertSame(SAVED_NEW, $page->save());

    // Create an auto-save item with a draft change.
    $page->set('title', 'Draft title');
    $auto_save_manager->saveEntity($page);
    $key = AutoSaveManager::getAutoSaveKey($page);
    $auto_save_store = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
    // Fetch auto-save item.
    $auto_save_item = $auto_save_store->get($key);
    self::assertIsArray($auto_save_item);

    // Store initial `original_hash` value to validate it's not changing.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $auto_save_item);
    self::assertNotEmpty($auto_save_item[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);
    $original_hash = $auto_save_item[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY];

    // Updating auto-save item via saveEntity() must not advance `original_hash`
    // to the current stored entity hash.
    $page->set('title', 'Updated draft title');
    $auto_save_manager->saveEntity($page);

    // Re-fetch updated auto-save item.
    $auto_save_item = $auto_save_store->get($key);
    self::assertIsArray($auto_save_item);

    // Check that the auto-save item was updated.
    self::assertArrayHasKey('label', $auto_save_item);
    self::assertEquals($page->label(), $auto_save_item['label']);
    // Check that the `original_hash` property value did not change.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $auto_save_item);
    self::assertEquals($original_hash, $auto_save_item[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);

    // No conflict should be detected at this stage.
    self::assertNull($auto_save_manager->getUnresolvedConflictForEntity($page));

    // Simulate an external update: save the page directly to storage,
    // bypassing the auto-save system, to create a conflict.
    $page->set('title', 'External update');
    $page->setNewRevision(TRUE);
    self::assertSame(SAVED_UPDATED, $page->save());
    $active_conflict = AutoSaveManager::getConflictId($page);
    self::assertNotNull($auto_save_manager->getUnresolvedConflictForEntity($page));
    self::assertSame($active_conflict, $auto_save_manager->getUnresolvedConflictForEntity($page));

    // Now call saveEntity() with additional draft changes while the conflict
    // is still unresolved. This simulates the client continuing to edit and
    // sending further auto-save requests without first resolving the conflict.
    $page->set('title', 'Further draft change');
    $auto_save_manager->saveEntity($page);

    // The conflict must still be detected.
    self::assertSame(
      $active_conflict,
      $auto_save_manager->getUnresolvedConflictForEntity($page),
      'saveEntity() must not clear an unresolved conflict by advancing original_hash.',
    );

    // Re-fetch updated auto-save item.
    $auto_save_item = $auto_save_store->get($key);
    self::assertIsArray($auto_save_item);
    // Check that the auto-save item was updated.
    self::assertArrayHasKey('label', $auto_save_item);
    self::assertEquals($page->label(), $auto_save_item['label']);
    // Check that the `original_hash` property value did not change.
    self::assertArrayHasKey(AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $auto_save_item);
    self::assertEquals($original_hash, $auto_save_item[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY]);
  }

  /**
   * Tests AutoSaveManager::getAllAutoSaveList parameters and conflict detection.
   *
   * @param bool $with_entities
   *   Whether the items in auto-save list should have 'entity' property with
   *   instances of EntityInterface.
   * @param bool $with_conflicts
   *   Whether the items in auto-save list should contain 'conflict_id'
   *   properties for auto-save items with conflicts due to external entity
   *   updates.
   * @param int $total_items
   *   Total expected count of items in the auto-save item list.
   * @param int $items_with_entity_instance
   *   Expected count of items with 'entity' properties containing instances of
   *   EntityInterface.
   * @param int $items_with_conflicts
   *   Expected count of items with 'conflict_id' properties.
   *
   * @legacy-covers \Drupal\canvas\AutoSave\AutoSaveManager::getAllAutoSaveList
   */
  #[TestWith([FALSE, FALSE, 5, 0, 0])]
  #[TestWith([TRUE, FALSE, 5, 5, 0])]
  #[TestWith([FALSE, TRUE, 5, 0, 2])]
  #[TestWith([TRUE, TRUE, 5, 5, 2])]
  public function testGetAllAutoSaveList(
    bool $with_entities,
    bool $with_conflicts,
    int $total_items,
    int $items_with_entity_instance,
    int $items_with_conflicts,
  ): void {
    // Create 3 Page content entities.
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page1 = Page::create([
      'title' => 'Test Page 1, please ignore',
      'components' => [],
    ]);
    \assert($page1 instanceof Page);
    self::assertEntityIsValid($page1);
    self::assertSame(SAVED_NEW, $page1->save());

    $page2 = Page::create([
      'title' => 'Test Page 2, please ignore',
      'components' => [],
    ]);
    \assert($page2 instanceof Page);
    self::assertEntityIsValid($page2);
    self::assertSame(SAVED_NEW, $page2->save());

    $page3 = Page::create([
      'title' => 'Test Page 3, please ignore',
      'components' => [],
    ]);
    \assert($page3 instanceof Page);
    self::assertEntityIsValid($page3);
    self::assertSame(SAVED_NEW, $page3->save());

    // Create 2 PageRegion config entities.
    $component_tree_1 = [
      [
        'uuid' => self::UUID_IN_ROOT,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => 'Test heading, please ignore',
        ],
      ],
    ];
    $page_region_1 = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => $component_tree_1,
    ]);
    \assert($page_region_1 instanceof PageRegion);
    self::assertEntityIsValid($page_region_1);
    $this->assertSame(SAVED_NEW, $page_region_1->save());

    $component_tree_2 = [
      [
        'uuid' => self::UUID_IN_ROOT,
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'd34b93534777207a',
        'inputs' => [
          'heading' => 'Test heading, please ignore',
        ],
      ],
    ];
    $page_region_2 = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_second',
      'component_tree' => $component_tree_2,
    ]);
    \assert($page_region_2 instanceof PageRegion);
    self::assertEntityIsValid($page_region_2);
    $this->assertSame(SAVED_NEW, $page_region_2->save());

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities, with_conflicts: $with_conflicts);
    self::assertIsArray($list);
    self::assertEmpty($list);

    // Modify all Page entities and add them to the auto-save.
    $page1->set('title', 'Updated title 1');
    $auto_save_manager->saveEntity($page1);

    $page2->set('title', 'Updated title 2');
    $auto_save_manager->saveEntity($page2);
    $page3->set('title', 'Updated title 3');
    $auto_save_manager->saveEntity($page3);

    // Modify all PageRegion entities and add them to the auto-save.
    $component_tree_1[0]['inputs']['heading'] = 'Updated heading, please ignore';
    $page_region_1->set('component_tree', $component_tree_1);
    self::assertEntityIsValid($page_region_1);
    $auto_save_manager->saveEntity($page_region_1);
    $component_tree_2[0]['inputs']['heading'] = 'Updated heading, please ignore';
    $page_region_2->set('component_tree', $component_tree_2);
    self::assertEntityIsValid($page_region_2);
    $auto_save_manager->saveEntity($page_region_2);

    // List before conflicts.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities, with_conflicts: $with_conflicts);
    self::assertIsArray($list);
    self::assertCount($total_items, $list);
    // The 'entity' property is always set.
    self::assertCount($total_items, \array_column($list, 'entity'));
    // But $with_entities controls if it contains null or entity instance.
    self::assertCount($items_with_entity_instance, \array_filter($list, fn(array $item) => $item['entity'] instanceof EntityInterface));
    if (!$with_entities) {
      self::assertCount($total_items, \array_filter($list, fn(array $item) => \is_null($item['entity'])));
    }
    // No conflicts exist, therefore no 'conflict_id' elements should be present
    // in any of the auto-save items, even if $with_conflicts is set to TRUE.
    self::assertCount(0, \array_column($list, 'conflict_id'));

    // Add conflicts to $page1 and $page2 entities.
    $page1->set('title', 'External title update 1');
    $this->assertSame(SAVED_UPDATED, $page1->save());
    $page2->set('title', 'External title update 2');
    $this->assertSame(SAVED_UPDATED, $page2->save());

    // List after conflicts created.
    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities, with_conflicts: $with_conflicts);
    self::assertIsArray($list);
    self::assertCount($total_items, $list);
    // The parameter $with_entities functions as before.
    self::assertCount($total_items, \array_column($list, 'entity'));
    self::assertCount($items_with_entity_instance, \array_filter($list, fn(array $item) => $item['entity'] instanceof EntityInterface));
    if (!$with_entities) {
      self::assertCount($total_items, \array_filter($list, fn(array $item) => \is_null($item['entity'])));
    }
    // The 'conflict_id' elements should be present in the auto-save items list
    // if $with_conflicts is set to TRUE.
    self::assertCount($items_with_conflicts, \array_column($list, 'conflict_id'));

    // Test that pre-existing auto-save entries without the 'original_hash'
    // property do not return false positives when checking for conflicts.
    $auto_save_store = $this->container->get('keyvalue')->get(AutoSaveManager::AUTO_SAVE_STORE);
    $auto_save_items_with_original_hash = $auto_save_store->getAll();
    $auto_save_items_without_original_hash = \array_map(fn (array $item) =>
      \array_diff_key($item, \array_flip([AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY])),
      $auto_save_items_with_original_hash
    );
    $auto_save_store->setMultiple($auto_save_items_without_original_hash);

    $list = $auto_save_manager->getAllAutoSaveList(with_entities: $with_entities, with_conflicts: $with_conflicts);
    self::assertIsArray($list);
    self::assertCount($total_items, $list);
    // The parameter $with_entities functions as before.
    self::assertCount($total_items, \array_column($list, 'entity'));
    self::assertCount($items_with_entity_instance, \array_filter($list, fn(array $item) => $item['entity'] instanceof EntityInterface));
    if (!$with_entities) {
      self::assertCount($total_items, \array_filter($list, fn(array $item) => \is_null($item['entity'])));
    }
    // The auto-save entity list does not detect conflicts for any auto-save
    // entities without 'original_hash', regardless of $with_conflicts.
    self::assertCount(0, \array_column($list, 'conflict_id'));
  }

}

/**
 * Test time service that allows time offset for testing.
 */
class AutoSaveManagerTestTime extends Time {

  /**
   * An offset to add to the request time.
   *
   * @var int
   */
  public static $offset = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return parent::getRequestTime() + static::$offset;
  }

}
