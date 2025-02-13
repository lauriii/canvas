<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\block\Entity\Block;
use Drupal\block\Plugin\DisplayVariant\BlockPageVariant;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Render\Plugin\DisplayVariant\SimplePageVariant;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;

/**
 * @group experience_builder
 */
class PageTemplateVariantTest extends FunctionalTestBase {

  use AssertPageCacheContextsAndTagsTrait;
  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use TestDataUtilitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  public function test(): void {
    // 1. Baseline Drupal: SimplePageVariant.
    $this->assertPageDisplayVariant(SimplePageVariant::class, []);
    $this->assertSame([], $this->getRenderedBlockIds());

    // 2. Block module installed: BlockPageVariant is used instead, but no
    // additional things appear on the page and hence no additional cache tags.
    $this->container->get(ModuleInstallerInterface::class)->install(['block']);
    $this->assertPageDisplayVariant(BlockPageVariant::class, []);
    $this->assertSame([], $this->getRenderedBlockIds());

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
    $this->assertSame([$block->id()], $this->getRenderedBlockIds());

    // 4. Experience Builder module installed: nothing changes.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant
    $this->container->get(ModuleInstallerInterface::class)->install([
      'experience_builder',
      // Install module that provides test SDCs.
      'xb_test_sdc',
    ]);
    $this->rebuildContainer();
    $this->generateComponentConfig();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block]);
    $this->assertSame([$block->id()], $this->getRenderedBlockIds());

    // 5. Once an Experience Builder PageTemplate config entity is created for
    // the default theme, XB's PageTemplateDisplayVariant is used instead.
    $generate_static_prop_source = function (string $label): array {
      return [
        'sourceType' => 'static:field_item:string',
        'value' => "Hello, $label!",
        'expression' => 'ℹ︎string␟value',
      ];
    };
    $pageTemplate = PageTemplate::create([
      'theme' => $this->defaultTheme,
      'component_trees' => [
        'sidebar_first' => NULL,
        'sidebar_second' => NULL,
        'content' => [
          'tree' => self::encodeXBData([
            ComponentTreeStructure::ROOT_UUID => [
              [
                'uuid' => 'uuid-in-root',
                'component' => 'sdc.xb_test_sdc.props-no-slots',
              ],
              ['uuid' => 'uuid-main', 'component' => 'block.system_main_block'],
              ['uuid' => 'uuid-local-actions', 'component' => 'block.local_actions_block'],
              ['uuid' => 'uuid-inaccessible', 'component' => 'block.user_login_block'],
              ['uuid' => 'uuid-title', 'component' => 'block.page_title_block'],
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
            'uuid-messages' => [
              'label' => '',
              'label_display' => FALSE,
            ],
          ]),
        ],
        'header' => NULL,
        'primary_menu' => NULL,
        'secondary_menu' => NULL,
        'footer' => NULL,
        'highlighted' => NULL,
        'help' => NULL,
        'page_top' => NULL,
        'page_bottom' => NULL,
        'breadcrumb' => NULL,
      ],
      'editable' => [
        'sidebar_first' => FALSE,
        'sidebar_second' => FALSE,
        'content' => TRUE,
        'header' => FALSE,
        'primary_menu' => FALSE,
        'secondary_menu' => FALSE,
        'footer' => FALSE,
        'highlighted' => FALSE,
        'help' => FALSE,
        'page_top' => FALSE,
        'page_bottom' => FALSE,
        'breadcrumb' => FALSE,
      ],
    ]);
    $pageTemplate->save();
    // ⚠️ In the future, we may want to reduce the number of cache tags and rely
    // solely on the XB PageTemplate config entity's cache tag. That would
    // require intersecting every XB Component config entity cache tag
    // invalidation against all XB PageTemplate config entities that depend
    // it, and then invalidating *those* cache tags. Since the number of
    // PageTemplate config entities is small (one per installed front-end theme)
    // this should be totally plausible. FOR NOW THIS WOULD BE PREMATURE
    // OPTIMIZATION.
    $this->assertPageDisplayVariant(PageTemplateDisplayVariant::class, Component::loadMultiple([
      'block.page_title_block',
      'block.local_actions_block',
      'block.system_main_block',
      'block.system_messages_block',
      'block.user_login_block',
      'sdc.xb_test_sdc.props-no-slots',
    ]), [], ['route']);
    $this->assertSame([
      'uuid-main',
      'uuid-title',
    ], $this->getRenderedBlockIds());

    // 6. If the Experience Builder PageTemplate config entity is disabled,
    // BlockPageVariant is used once again.
    $pageTemplate->disable()->save();
    $this->assertPageDisplayVariant(BlockPageVariant::class, [$block]);
    $this->assertSame([$block->id()], $this->getRenderedBlockIds());
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
      // The `config:experience_builder.page_template.stark` cache tag appears
      // on top of the baseline.
      PageTemplateDisplayVariant::class => [
        ...$expected_baseline_cache_tags,
        'config:experience_builder.page_template.stark',
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

  /**
   * @see template_preprocess_block()
   * @return string[]
   */
  private function getRenderedBlockIds(): array {
    return array_map(
      fn (NodeElement $e) => substr((string) $e->getAttribute('id'), strlen('block-')),
      $this->getSession()->getPage()->findAll('css', '[id^=block-]')
    );
  }

}
