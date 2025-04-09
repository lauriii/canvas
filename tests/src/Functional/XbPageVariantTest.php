<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Drupal\block\Entity\Block;
use Drupal\block\Plugin\DisplayVariant\BlockPageVariant;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Render\Plugin\DisplayVariant\SimplePageVariant;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\user\Entity\Role;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @group experience_builder
 * @covers \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant
 */
class XbPageVariantTest extends FunctionalTestBase {

  use AssertPageCacheContextsAndTagsTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;

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
    // XB UI, to allow testing that independently.
    $this->mink->registerSession('xb_ui', new Session($this->getDefaultDriverInstance()));
    $this->mink->setDefaultSessionName('xb_ui');
    $admin_user = $this->createUser(['access administration pages']);
    assert($admin_user instanceof AccountInterface);
    $this->drupalLogin($admin_user);
    $this->assertSession('xb_ui');
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

    // 4. Experience Builder module installed: nothing changes.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant
    $this->container->get(ModuleInstallerInterface::class)->install([
      'experience_builder',
      // Install module that provides test SDCs.
      'xb_test_sdc',
    ]);
    $this->rebuildContainer();
    $this->generateComponentConfig();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block]);
    $this->assertSame([
      'blocks' => [$block->id()],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());

    // 5. Once >=1 enabled Experience Builder PageRegion config entity is
    // created for the default theme, XB's XbPageVariant is used instead.
    $slogan = 'JavaScript is the future!';
    $this->config('system.site')->set('slogan', $slogan)->save();
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $pageRegion = PageRegion::create([
      'theme' => $this->defaultTheme,
      'region' => 'sidebar_first',
      'component_tree' => [
        'tree' => self::encodeXBData([
          ComponentTreeStructure::ROOT_UUID => [
            [
              'uuid' => 'uuid-in-root',
              'component' => 'sdc.xb_test_sdc.props-no-slots',
            ],
            ['uuid' => 'uuid-local-actions', 'component' => 'block.local_actions_block'],
            ['uuid' => 'uuid-inaccessible', 'component' => 'block.user_login_block'],
            ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
            ['uuid' => 'uuid-branding', 'component' => 'block.system_branding_block'],
            [
              'uuid' => 'uuid-messages',
              'component' => 'block.system_messages_block',
            ],
            [
              'uuid' => 'uuid-in-root-another',
              'component' => 'sdc.xb_test_sdc.props-no-slots',
            ],
          ],
        ]),
        'inputs' => self::encodeXBData([
          'uuid-in-root' => [
            'heading' => $generate_static_prop_source('world'),
          ],
          'uuid-in-root-another' => [
            'heading' => $generate_static_prop_source('another world'),
          ],
          // Note how there is no input for the user login block, the main
          // content block, but there is for all others.
          // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::getExplicitInput()
          'uuid-local-actions' => [
            'label' => '',
            'label_display' => FALSE,
          ],
          'uuid-title' => [
            'label' => '',
            'label_display' => FALSE,
          ],
          'uuid-branding' => [
            'label' => '',
            'label_display' => FALSE,
            'use_site_logo' => FALSE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
          'uuid-messages' => [
            'label' => '',
            'label_display' => FALSE,
          ],
        ]),
      ],
    ]);
    $pageRegion->save();
    // ⚠️ In the future, we may want to reduce the number of cache tags and rely
    // solely on the XB PageRegion config entity's list cache tag. That would
    // require intersecting every XB Component config entity cache tag
    // invalidation against all XB PageTemplate config entities that depend
    // it, and then invalidating *those* cache tags. Since the number of
    // PageRegion config entities is relatively small (one per region per theme)
    // this should be totally plausible. FOR NOW THIS WOULD BE PREMATURE
    // OPTIMIZATION.
    $this->assertPageDisplayVariant(XbPageVariant::class, Component::loadMultiple([
      'block.page_title_block',
      'block.system_branding_block',
      'block.local_actions_block',
      'block.system_messages_block',
      'block.user_login_block',
      'sdc.xb_test_sdc.props-no-slots',
    ]), [], ['route']);
    // The branding block is rendered using Twig, no Astro island found.
    $this->assertSame([
      'blocks' => ['uuid-title', 'uuid-branding'],
      'js_components' => [],
    ], $this->getRenderedComponentInstances());
    $assert_session->responseContains('rel="home">Drupal</a>');
    $assert_session->pageTextContains($slogan);

    // 6. Creating an exposed JavaScriptComponent config entity that overrides
    // a placed `block`-sourced Component results in that block being rendered
    // using an Astro island.
    $this->container->get(ModuleInstallerInterface::class)->install(['xb_dev_js_blocks']);
    // @see tests/modules/xb_dev_js_blocks/config/install/experience_builder.js_component.site_branding.yml
    $branding_component = JavaScriptComponent::load('site_branding');
    assert($branding_component instanceof JavaScriptComponent);
    $role = Role::load('anonymous');
    $this->assertInstanceOf(Role::class, $role);
    $this->assertPageDisplayVariant(
      XbPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.system_branding_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.xb_test_sdc.props-no-slots',
      ]),
      expected_additional_cache_tags: [
        ...$branding_component->getCacheTags(),
      ],
      expected_additional_cache_contexts: ['route'],
    );
    // The branding block is NOT rendered by Twig anymore, Astro island found,
    // using the branding Block component instance UUID.
    // @see \Drupal\experience_builder\Element\AstroIsland
    $this->assertSame([
      'blocks' => ['uuid-title'],
      'js_components' => ['uuid-branding'],
    ], $this->getRenderedComponentInstances());
    $assert_session->responseNotContains('rel="home">Drupal</a>');
    $this->assertRenderedJavaScriptComponent(
      html: $this->getSession()->getPage()->getHtml(),
      uid: 'uuid-branding',
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // 7. Creating a draft version of the JavaScriptComponent config entity (by
    // simulating using XB's in-browser code component editor having auto-saved
    // changes) should result in … NO changes on the front page! Because auto-
    // saved data must only appear inside XB's UI.
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    $autoSaveManager->save(
      entity: $branding_component,
      data: ['name' => 'Site branding updated'] + $branding_component->normalizeForClientSide()->values,
    );
    $this->assertSame('Site branding', $branding_component->label());
    $autoSaveData = $autoSaveManager->getAutoSaveData($branding_component)->data;
    self::assertNotNull($autoSaveData);
    $branding_component_auto_saved = JavaScriptComponent::create($autoSaveData);
    $this->assertSame('Site branding updated', $branding_component_auto_saved->label());
    $this->assertPageDisplayVariant(
      XbPageVariant::class,
      Component::loadMultiple([
        'block.page_title_block',
        'block.system_branding_block',
        'block.local_actions_block',
        'block.system_messages_block',
        'block.user_login_block',
        'sdc.xb_test_sdc.props-no-slots',
      ]),
      expected_additional_cache_tags: [
        // ⚠️ Note the absence of the auto-save cache tag, which correctly
        // conveys auto-saved data is not even being considered when rendering
        // the front page.
        // @see \Drupal\experience_builder\AutoSave\AutoSaveManager::CACHE_TAG
        ...$branding_component->getCacheTags(),
      ],
      expected_additional_cache_contexts: ['route'],
    );
    // Ensure the auto-saved component is NOT rendered on the front page.
    $this->assertRenderedJavaScriptComponent(
      html: $this->getSession()->getPage()->getHtml(),
      uid: 'uuid-branding',
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // Switch to the authenticated session, because ::drupalGet() does not allow
    // specifying a session.
    self::assertNotNull($this->mink);
    $this->mink->setDefaultSessionName('xb_ui');

    // XB UI: 1. The draft version of the JavaScriptComponent is rendered.
    // (The XB UI must preview all changes that, to allow reviewing and then
    // publishing them.)
    $xb_ui_session = $this->getSession('xb_ui');
    $page = Page::create(['title' => 'Test page']);
    $page->save();
    $this->drupalGet(Url::fromRoute('experience_builder.api.layout.get', ['entity' => $page->id(), 'entity_type' => 'xb_page']));
    $this->assertSame('application/json', $xb_ui_session->getResponseHeader('Content-Type'));
    $layout_response_decoded = json_decode($xb_ui_session->getPage()->getContent(), TRUE);
    $this->assertArrayHasKey('html', $layout_response_decoded);
    $this->assertRenderedJavaScriptComponent(
      html: $layout_response_decoded['html'],
      uid: 'uuid-branding',
      expected_opts: ['name' => $branding_component_auto_saved->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // XB UI: 2. Deleting the auto-saved JavaScriptComponent results in the
    // saved JavaScriptComponent being rendered.
    $autoSaveManager->delete($branding_component);
    $xb_ui_session->reload();
    $layout_response_decoded = json_decode($xb_ui_session->getPage()->getContent(), TRUE);
    $this->assertRenderedJavaScriptComponent(
      html: $layout_response_decoded['html'],
      uid: 'uuid-branding',
      expected_opts: ['name' => $branding_component->label(), 'value' => 'preact'],
      expected_slots: ['siteSlogan' => $slogan],
    );

    // Switch back to the anonymous session.
    self::assertNotNull($this->mink);
    $this->mink->setDefaultSessionName('default');

    // 8. If all Experience Builder PageRegion config entities are disabled,
    // BlockPageVariant is used once again.
    $pageRegion->disable()->save();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block]);
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
      // The `config:experience_builder.page_region.stark.sidebar_first` cache tag
      // appears on top of the baseline.
      XbPageVariant::class => [
        ...$expected_baseline_cache_tags,
        'config:experience_builder.page_region.stark.sidebar_first',
        ...$expected_dependency_cacheability->getCacheTags(),
        ...$expected_additional_cache_tags,
      ],
      default => throw new \OutOfRangeException(),
    };

    $this->rebuildAll();
    $this->drupalGet('');
    $this->assertCacheTags($expected_cache_tags, FALSE);
    $this->assertCacheContexts(array_merge([
      'languages:language_interface',
      'theme',
      'url.path',
      'url.query_args',
      'user.permissions',
      'user.roles:authenticated',
    ], $expected_additional_cache_contexts), NULL, FALSE);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache-Max-Age', '-1 (Permanent)');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Dynamic-Cache', 'MISS');
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
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::renderComponent()
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
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::renderComponent()
   * @return string[]
   */
  private function getRenderedJavaScriptComponentIds(): array {
    return array_map(
      fn (NodeElement $e) => (string) $e->getAttribute('uid'),
      $this->getSession()->getPage()->findAll('css', 'astro-island')
    );
  }

  /**
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::renderComponent()
   */
  private function assertRenderedJavaScriptComponent(string $html, string $uid, array $expected_opts, array $expected_slots): void {
    // TRICKY: use Crawler to also be able to assert HTML embedded in a JSON
    // response.
    $js_component = (new Crawler($html))->filter("astro-island[uid='$uid']");
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
      assert($actual_slots[$slot_index] instanceof \DOMElement);
      $this->assertSame($expected_slot_name, $actual_slots[$slot_index]->getAttribute('data-astro-template'));
      $this->assertSame($expected_slot_contents, $actual_slots[$slot_index]->textContent);
      $slot_index++;
    }
  }

}
