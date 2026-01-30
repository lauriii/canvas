<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Drupal\block\Entity\Block;
use Drupal\block\Plugin\DisplayVariant\BlockPageVariant;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\PropSourceBase;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Render\Plugin\DisplayVariant\SimplePageVariant;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\user\Entity\Role;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * @group canvas
 * @covers \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
 */
class CanvasPageVariantTest extends FunctionalTestBase {

  use AssertPageCacheContextsAndTagsTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;

  private const string UUID_IN_ROOT = '16176e0b-8197-40e3-ad49-48f1b6e9a7f9';
  private const string UUID_LOCAL_ACTIONS = '8f6780cd-7b64-499e-9545-321a14951a0d';
  private const string UUID_INACCESSIBLE = '208452de-10d6-4fb8-89a1-10e340b3744c';
  private const string UUID_TITLE = '5fc4de04-f59c-4f56-b576-4673433381a4';
  private const string UUID_BRANDING = 'b8fd639d-f1df-413a-8926-8d2c7a3d6493';
  private const string UUID_MESSAGES = '4d866c38-7261-45c6-9b1e-0b94096d51e8';
  private const string UUID_IN_ROOT_ANOTHER = '5944ef12-4a3d-4f3a-8e67-086661be9ffc';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function registerSessions(): void {
    // The default session is used to assert as the anonymous user what the
    // front page looks like in various configuration states of the site.
    // @see ::initMink
    self::assertNotNull($this->mink);
    self::assertSame('default', $this->mink->getDefaultSessionName());
    $this->assertSession('default');

    // Register a second session for an authenticated user that can access the
    // Canvas UI, to allow testing that independently.
    $this->mink?->registerSession('canvas_ui', new Session($this->getDefaultDriverInstance()));
    $this->mink?->setDefaultSessionName('canvas_ui');
    /** @var \Drupal\user\UserInterface $admin_user */
    $admin_user = $this->createUser(name: 'Eddy the Admin');
    // cspell:ignore canvaspageadmin
    Role::create([
      'id' => 'canvaspageadmin',
      'label' => 'Canvas page admin',
    ])->save();
    $admin_user->addRole('canvaspageadmin')->save();
    \assert($admin_user instanceof AccountInterface);
    $this->drupalLogin($admin_user);
    $this->assertSession('canvas_ui');
  }

  public function test(): void {
    self::assertNotNull($this->mink);
    $this->mink->setDefaultSessionName('default');
    $assert_session = $this->assertSession();

    // 1. Baseline Drupal: SimplePageVariant.
    $this->assertPageDisplayVariant(SimplePageVariant::class, []);
    $this->assertSame([
      'blocks' => [],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());

    // 2. Block module installed: BlockPageVariant is used instead, but no
    // additional things appear on the page and hence no additional cache tags.
    $this->container->get(ModuleInstallerInterface::class)->install(['block']);
    $this->assertPageDisplayVariant(BlockPageVariant::class, []);
    $this->assertSame([
      'blocks' => [],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());

    // 3. Once a Block config entity is created for the default theme, its block
    // plugin's render output appears and its cache tags appear.
    $block = Block::create([
      'id' => $this->randomMachineName(8),
      'theme' => $this->defaultTheme,
      'region' => 'content',
      'plugin' => 'system_powered_by_block',
    ]);
    $block->save();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block]);
    $this->assertSame([
      'blocks' => [$block->id()],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());

    // 4. Drupal Canvas module installed: nothing changes, except the
    //    conditional attaching of the global asset library, which adds the
    //    `route.name` cache context.
    // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
    // @see \Drupal\canvas\Hook\ComponentSourceHooks::pageAttachments
    $this->container->get(ModuleInstallerInterface::class)->install([
      'canvas',
      // Install module that provides test SDCs.
      'canvas_test_sdc',
    ]);
    Role::load('canvaspageadmin')?->grantPermission(Page::EDIT_PERMISSION)->save();
    $this->rebuildContainer();
    $this->generateComponentConfig();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block], expected_additional_cache_contexts: ['route.name']);
    $this->assertSame([
      'blocks' => [$block->id()],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());

    // 5. Once >=1 enabled Drupal Canvas PageRegion config entity is
    // created for the default theme, Canvas's CanvasPageVariant is used instead.
    $slogan = 'JavaScript is the future!';
    $this->config('system.site')->set('slogan', $slogan)->save();
    $pageRegion = PageRegion::create([
      'theme' => $this->defaultTheme,
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_IN_ROOT,
          'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
          'component_version' => 'b1e991f726a2a266',
          'inputs' => [
            'heading' => "Hello, world!",
          ],
        ],
        [
          'uuid' => self::UUID_LOCAL_ACTIONS,
          'component_id' => 'block.local_actions_block',
          'component_version' => '5400750e38fff980',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
        [
          'uuid' => self::UUID_INACCESSIBLE,
          'component_id' => 'block.user_login_block',
          'component_version' => '327cdbb3ce86dda9',
          // Note how there is no input for the user login block, the main
          // content block, but there is for all others.
          // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::getExplicitInput()
          'inputs' => [],
        ],
        [
          'uuid' => self::UUID_TITLE,
          'component_id' => 'block.page_title_block',
          'component_version' => '62af221149ae4887',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
        [
          'uuid' => self::UUID_BRANDING,
          'component_id' => 'block.system_branding_block',
          'component_version' => '247a23298360adb2',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
            'use_site_logo' => FALSE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
        ],
        [
          'uuid' => self::UUID_MESSAGES,
          'component_id' => 'block.system_messages_block',
          'component_version' => 'b92f802cf68eb83e',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
        [
          'uuid' => self::UUID_IN_ROOT_ANOTHER,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'component_version' => '85a5c0c7dd53e0bb',
          'inputs' => [
            'heading' => [
              'sourceType' => PropSource::Static->value . PropSourceBase::SOURCE_TYPE_PREFIX_SEPARATOR . 'field_item:entity_reference',
              'value' => [
                // @see ::setUp()
                'target_id' => 2,
              ],
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:user␝name␞␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'target_type' => 'user',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    // The UUID_IN_ROOT_ANOTHER component instance is doing something funky to
    // test bubbling of cacheability: it populates a "string" prop shape with
    // the name of a referenced User entity. Canvas allows for this, but only if
    // the Component config entity is configured to do exactly this: component
    // instances must comply with the referenced Component version.
    self::assertSame([
      'Using a static prop source that deviates from the configuration for Component <em class="placeholder">sdc.canvas_test_sdc.props-slots</em> at version <em class="placeholder">85a5c0c7dd53e0bb</em>.',
    ], array_map(
      fn (ConstraintViolationInterface $v) => (string) $v->getMessage(),
      iterator_to_array($pageRegion->getTypedData()->validate()),
    ));
    // Create a new version on the Component that shows the name of a User.
    $component = Component::load('sdc.canvas_test_sdc.props-slots');
    self::assertInstanceOf(Component::class, $component);
    self::assertCount(1, $component->getVersions());
    $new_settings = $component->getSettings();
    $new_settings['prop_field_definitions']['heading']['field_type'] = 'entity_reference';
    $new_settings['prop_field_definitions']['heading']['field_storage_settings'] = ['target_type' => 'user'];
    $new_settings['prop_field_definitions']['heading']['default_value'][0] = ['target_id' => '0'];
    $new_settings['prop_field_definitions']['heading']['expression'] = 'ℹ︎entity_reference␟entity␜␜entity:user␝name␞␟value';
    $new_settings['prop_field_definitions']['heading']['field_widget'] = 'entity_reference_autocomplete';
    $source = $this->container->get(ComponentSourceManager::class)->createInstance(SingleDirectoryComponent::SOURCE_PLUGIN_ID, [
      'local_source_id' => 'canvas_test_sdc:props-slots',
      ...$new_settings,
    ]);
    \assert($source instanceof ComponentSourceBase);
    $component->createVersion($source->generateVersionHash())
      ->setSettings($new_settings)
      ->save();
    self::assertCount(2, $component->getVersions());
    // Update the component instance.
    $tree = $pageRegion->getComponentTree();
    $index = $tree->getComponentTreeDeltaByUuid(self::UUID_IN_ROOT_ANOTHER);
    \assert($index !== NULL);
    $tree->removeItem($index);
    $tree->appendItem([
      'uuid' => self::UUID_IN_ROOT_ANOTHER,
      'component_id' => 'sdc.canvas_test_sdc.props-slots',
      // New Component version.
      'component_version' => $component->getActiveVersion(),
      // Collapsed inputs.
      'inputs' => [
        'heading' => ['target_id' => 2],
      ],
    ]);
    $pageRegion->setComponentTree($tree->getValue());
    self::assertSame([], array_map(
      fn (ConstraintViolationInterface $v) => (string) $v->getMessage(),
      iterator_to_array($pageRegion->getTypedData()->validate()),
    ));
    $pageRegion->save();

    // ⚠️ In the future, we may want to reduce the number of cache tags and rely
    // solely on the Canvas PageRegion config entity's list cache tag. That would
    // require intersecting every Canvas Component config entity cache tag
    // invalidation against all Canvas PageTemplate config entities that depend
    // it, and then invalidating *those* cache tags. Since the number of
    // PageRegion config entities is relatively small (one per region per theme)
    // this should be totally plausible. FOR NOW THIS WOULD BE PREMATURE
    // OPTIMIZATION.
    $this->assertPageDisplayVariant(CanvasPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.system_branding_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.canvas_test_sdc.props-no-slots',
        // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
        'sdc.canvas_test_sdc.props-slots',
      ]),
      expected_additional_cache_tags: [],
      expected_additional_cache_contexts: [
        'route',
        // @see \Drupal\user\UserAccessControlHandler::checkAccess()
        'user',
      ],
    );
    // The branding block is rendered using Twig, no Astro island found.
    $this->assertSame([
      'blocks' => [self::UUID_TITLE, self::UUID_BRANDING],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());
    $assert_session->responseContains('rel="home">Drupal</a>');
    $assert_session->pageTextContains($slogan);
    $assert_session->pageTextNotContains('Eddy the Admin');

    // 6. The UUID_IN_ROOT_ANOTHER component instance is populated by the name
    // of a referenced User. Granting the permission must have IMMEDIATE effect.
    Role::load('anonymous')?->grantPermission('access user profiles')->save();
    $this->assertPageDisplayVariant(CanvasPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.system_branding_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.canvas_test_sdc.props-no-slots',
        // Extra Component is rendered, because its required `heading` prop is
        // actually populated thanks to granting the permission.
        'sdc.canvas_test_sdc.props-slots',
      ]),
      // The name of User 2 is displayed, so its cache tag must be present.
      expected_additional_cache_tags: ['user:2'],
      expected_additional_cache_contexts: ['route'],
    );
    $assert_session->pageTextContains('Eddy the Admin');

    // 7. Revoking the permission must IMMEDIATELY hide the user name.
    Role::load('anonymous')?->revokePermission('access user profiles')->save();
    $this->assertPageDisplayVariant(CanvasPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.system_branding_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.canvas_test_sdc.props-no-slots',
        // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
        'sdc.canvas_test_sdc.props-slots',
      ]),
      // ⚠️ Note the absence of the `user:2` cache tag, which correctly
      // conveys User 2's data is not even being considered when rendering the
      // front page:
      // - of course, no User 2 data is rendered
      // - but also, the access control logic does not inspect anything specific
      //   about User 2: it only checks permissions and whether the referenced
      //   User matches the actively logged in User (hence the `user` cache
      //   context).
      expected_additional_cache_tags: [],
      expected_additional_cache_contexts: [
        'route',
        // @see \Drupal\user\UserAccessControlHandler::checkAccess()
        'user',
      ],
    );
    $assert_session->pageTextNotContains('Eddy the Admin');

    // @todo add test coverage installs a code component rendering `drupalSettings.canvasData.v0.branding`.
    // 8. Creating an exposed JavaScriptComponent config entity that overrides
    // a placed `block`-sourced Component results in that block being rendered
    // using an Astro island.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_e2e_code_components']);
    // @see tests/modules/canvas_test_e2e_code_components/config/install/canvas.js_component.site_branding.yml
    $branding_component = JavaScriptComponent::load('site_branding');
    \assert($branding_component instanceof JavaScriptComponent);
    $branding_component->enable()->save();
    $matching_component = Component::load(JsComponent::componentIdFromJavascriptComponentId($branding_component->id()));
    \assert($matching_component instanceof ComponentInterface);
    $tree = $pageRegion->getComponentTree();
    // Replace the block item with a JS Component.
    $index = $tree->getComponentTreeDeltaByUuid(self::UUID_BRANDING);
    \assert($index !== NULL);
    $tree->removeItem($index);
    $tree->appendItem([
      'uuid' => self::UUID_BRANDING,
      'component_id' => 'js.site_branding',
      'inputs' => [
        'logo' => 'https://llama.land/icon-small.png',
        'homeUrl' => 'https://llama.land',
        'siteName' => 'Llama land',
      ],
    ]);
    $tree->appendItem([
      'uuid' => '257f06f0-898a-4d03-b4ed-9ad506d57630',
      'parent_uuid' => self::UUID_BRANDING,
      'slot' => 'siteSlogan',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'inputs' => [
        'heading' => $slogan,
      ],
    ]);
    $pageRegion->setComponentTree($tree->getValue());
    $pageRegion->save();
    $role = Role::load('anonymous');
    $this->assertInstanceOf(Role::class, $role);
    $this->assertPageDisplayVariant(
      CanvasPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.canvas_test_sdc.props-no-slots',
        // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
        'sdc.canvas_test_sdc.props-slots',
      ]),
      expected_additional_cache_tags: [
        ...$branding_component->getCacheTags(),
        ...$matching_component->getCacheTags(),
      ],
      expected_additional_cache_contexts: [
        'route',
        // @see \Drupal\user\UserAccessControlHandler::checkAccess()
        'user',
      ],
    );
    // The branding block is NOT rendered by Twig anymore, Astro island found,
    // using the branding Block component instance UUID.
    // @see \Drupal\canvas\Element\AstroIsland
    $this->assertSame([
      'blocks' => [self::UUID_TITLE],
      'js_components' => [self::UUID_BRANDING],
    ], $this->getRenderedComponentInstances());
    $assert_session->responseNotContains('rel="home">Drupal</a>');
    $this->assertRenderedJavaScriptComponent(
      html: $this->getSession()->getPage()->getHtml(),
      uid: self::UUID_BRANDING,
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // 9. Creating a draft version of the JavaScriptComponent config entity (by
    // simulating using Canvas's in-browser code component editor having auto-saved
    // changes) should result in … NO changes on the front page! Because auto-
    // saved data must only appear inside Canvas's UI.
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $draft = JavaScriptComponent::create([
      'name' => 'Site branding updated',
    ] + $branding_component->toArray());
    $autoSaveManager->saveEntity($draft);
    $this->assertSame('Site branding', $branding_component->label());
    $autoSaveData = $autoSaveManager->getAutoSaveEntity($branding_component);
    self::assertInstanceOf(JavaScriptComponent::class, $autoSaveData->entity);
    $branding_component_auto_saved = $autoSaveData->entity;
    $this->assertSame('Site branding updated', $branding_component_auto_saved->label());
    $this->assertPageDisplayVariant(
      CanvasPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.canvas_test_sdc.props-no-slots',
        // @todo Stop expecting this cache tag in https://www.drupal.org/i/3559820
        'sdc.canvas_test_sdc.props-slots',
      ]),
      expected_additional_cache_tags: [
        // ⚠️ Note the absence of the auto-save cache tag, which correctly
        // conveys auto-saved data is not even being considered when rendering
        // the front page.
        // @see \Drupal\canvas\AutoSave\AutoSaveManager::CACHE_TAG
        ...$branding_component->getCacheTags(),
        ...$matching_component->getCacheTags(),
      ],
      expected_additional_cache_contexts: [
        'route',
        // @see \Drupal\user\UserAccessControlHandler::checkAccess()
        'user',
      ],
    );
    // Ensure the auto-saved component is NOT rendered on the front page.
    $this->assertRenderedJavaScriptComponent(
      html: $this->getSession()->getPage()->getHtml(),
      uid: self::UUID_BRANDING,
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // Switch to the authenticated session, because ::drupalGet() does not allow
    // specifying a session.
    self::assertNotNull($this->mink);
    $this->mink->setDefaultSessionName('canvas_ui');

    // Canvas UI: 1. The draft version of the JavaScriptComponent is rendered.
    // (The Canvas UI must preview all changes that, to allow reviewing and then
    // publishing them.)
    $canvas_ui_session = $this->getSession('canvas_ui');
    $page = Page::create(['title' => 'Test page']);
    $page->save();
    $this->drupalGet(Url::fromRoute('canvas.api.layout.get', ['entity' => $page->id(), 'entity_type' => Page::ENTITY_TYPE_ID]));
    $this->assertSame('application/json', $canvas_ui_session->getResponseHeader('Content-Type'));
    $layout_response_decoded = json_decode($canvas_ui_session->getPage()->getContent(), TRUE);
    $this->assertArrayHasKey('html', $layout_response_decoded);
    $this->assertRenderedJavaScriptComponent(
      html: $layout_response_decoded['html'],
      uid: self::UUID_BRANDING,
      expected_opts: ['name' => $branding_component_auto_saved->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // Canvas UI: 2. Deleting the auto-saved JavaScriptComponent results in the
    // saved JavaScriptComponent being rendered.
    $autoSaveManager->delete($branding_component);
    $canvas_ui_session->reload();
    $layout_response_decoded = json_decode($canvas_ui_session->getPage()->getContent(), TRUE);
    $this->assertRenderedJavaScriptComponent(
      html: $layout_response_decoded['html'],
      uid: self::UUID_BRANDING,
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // Switch back to the anonymous session.
    self::assertNotNull($this->mink);
    $this->mink->setDefaultSessionName('default');

    // 10. If all Drupal Canvas PageRegion config entities are disabled,
    // BlockPageVariant is used once again.
    $pageRegion->disable()->save();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block], expected_additional_cache_contexts: ['route.name']);
    $this->assertSame([
      'blocks' => [$block->id()],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());
  }

  private function assertPageDisplayVariant(string $expected_page_display_variant_class, array $expected_cacheable_dependencies, array $expected_additional_cache_tags = [], array $expected_additional_cache_contexts = []): void {
    $expected_baseline_cache_tags = [
      // These 3 cache tags originate from \Drupal\user\Form\UserLoginForm.
      'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form',
      'config:system.site',
      'config:user.role.anonymous',
      // These 2 are generically added by Drupal's Render API.
      'http_response',
      'rendered',
    ];
    $expected_dependency_cacheability = new CacheableMetadata();
    array_walk(
      $expected_cacheable_dependencies,
      fn (CacheableDependencyInterface $dep) => $expected_dependency_cacheability->addCacheableDependency($dep)
    );

    $expected_cache_tags = match ($expected_page_display_variant_class) {
      // Only the baseline cache tags: SimplePageVariant has no configurability,
      // hence it depends on no additional context, hence no added cache tags.
      SimplePageVariant::class => [
        ...$expected_baseline_cache_tags,
        ...$expected_additional_cache_tags,
      ],
      BlockPageVariant::class => [
        ...$expected_baseline_cache_tags,
        // The `config:block_list` cache tag appears on top of the baseline.
        'config:block_list',
        // If >=1 Block config entity is placed, the `block_view` cache tag also
        // appears.
        ...(!empty($expected_cacheable_dependencies) ? ['block_view'] : []),
        ...$expected_dependency_cacheability->getCacheTags(),
        ...$expected_additional_cache_tags,
      ],
      // The `config:canvas.page_region.stark.sidebar_first` cache tag
      // appears on top of the baseline.
      CanvasPageVariant::class => [
        ...$expected_baseline_cache_tags,
        'config:canvas.page_region.stark.sidebar_first',
        ...$expected_dependency_cacheability->getCacheTags(),
        ...$expected_additional_cache_tags,
      ],
      default => throw new \OutOfRangeException(),
    };

    $this->rebuildAll();
    $this->drupalGet('');
    $this->assertCacheTags($expected_cache_tags, FALSE);
    $expected_cache_contexts = array_merge([
      'languages:language_interface',
      'theme',
      'url.path',
      'url.query_args',
      'user.permissions',
      'user.roles:authenticated',
    ], $expected_additional_cache_contexts);
    $optimized_cache_contexts = $this->container->get(CacheContextsManager::class)->optimizeTokens($expected_cache_contexts);
    $this->assertCacheContexts($optimized_cache_contexts, include_default_contexts: FALSE);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', '-1 (Permanent)');
    $expected_dynamic_page_cache_miss = !in_array('user', $expected_cache_contexts, TRUE)
      ? 'MISS'
      : 'UNCACHEABLE (poor cacheability)';
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', $expected_dynamic_page_cache_miss);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
  }

  private function getRenderedComponentInstances(): array {
    // TRICKY: ideally, we'd also discover SDCs here, but there's no reliable
    // mechanism to detect them (`data-component-id` is optional).
    return [
      'blocks' => $this->getRenderedBlockIds(),
      'js_components' => $this->getRenderedJavaScriptComponentIds(),
    ];
  }

  /**
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::renderComponent()
   * @see template_preprocess_block()
   * @return string[]
   */
  private function getRenderedBlockIds(): array {
    return array_map(
      fn (NodeElement $e) => substr((string) $e->getAttribute('id'), strlen('block-')),
      $this->getSession()->getPage()->findAll('css', '[id^=block-]')
    );
  }

  /**
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
   * @return string[]
   */
  private function getRenderedJavaScriptComponentIds(): array {
    return array_map(
      fn (NodeElement $e) => (string) $e->getAttribute('uid'),
      $this->getSession()->getPage()->findAll('css', 'canvas-island')
    );
  }

  /**
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
   */
  private function assertRenderedJavaScriptComponent(string $html, string $uid, array $expected_opts, array $expected_slots): void {
    // TRICKY: use Crawler to also be able to assert HTML embedded in a JSON
    // response.
    $js_component = (new Crawler($html))->filter("canvas-island[uid='$uid']");
    self::assertCount(1, $js_component);

    // Assert opts.
    self::assertJsonStringEqualsJsonString(
      Json::encode($expected_opts),
      $js_component->attr('opts') ?? ''
    );

    // Assert slots.
    $actual_slots = $js_component->filter('template[data-astro-template]')->getIterator();
    $this->assertCount(count($expected_slots), $actual_slots);
    $slot_index = 0;
    foreach ($expected_slots as $expected_slot_name => $expected_slot_contents) {
      \assert($actual_slots[$slot_index] instanceof \DOMElement);
      $this->assertSame($expected_slot_name, $actual_slots[$slot_index]->getAttribute('data-astro-template'));
      $this->assertSame($expected_slot_contents, \trim($actual_slots[$slot_index]->textContent));
      $slot_index++;
    }
  }

}
