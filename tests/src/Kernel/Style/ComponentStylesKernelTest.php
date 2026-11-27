<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Style;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\StyleLibrary;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Style\ComponentStyles;
use Drupal\canvas\Style\StyleSourceInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests component styles on the core Style API.
 *
 * Runs with core's Style API or, without it, the canvas_style_polyfill module.
 *
 * @see https://www.drupal.org/project/drupal/issues/3517033
 */
#[CoversClass(ComponentStyles::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ComponentStylesKernelTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;
  use PageTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::PAGE_TEST_MODULES,
    'canvas_test_styles',
    // Inert when core has the Style API, so these tests cover whichever
    // source is in use: run them on both kinds of core to compare the two.
    'canvas_style_polyfill',
  ];

  private ComponentStyles $styles;

  protected function setUp(): void {
    parent::setUp();
    $this->installPageEntitySchema();
    $this->generateComponentConfig();
    $this->config('canvas.settings')->set('experimental.styles', TRUE)->save();
    $this->styles = $this->container->get(ComponentStyles::class);
    $this->styles->reset();
  }

  private static function component(string $id): Component {
    $component = Component::load($id);
    self::assertInstanceOf(Component::class, $component);
    return $component;
  }

  private static function loadPage(int $id): Page {
    $page = Page::load($id);
    self::assertInstanceOf(Page::class, $page);
    return $page;
  }

  private static function tree(Page $page): ComponentTreeItemList {
    $tree = $page->get('components');
    self::assertInstanceOf(ComponentTreeItemList::class, $tree);
    return $tree;
  }

  public function testDefinitionsAndAcceptedStyles(): void {
    $definitions = $this->styles->definitions();
    // Page-level targets are never offered.
    self::assertArrayNotHasKey('page_scheme', $definitions);
    // Deprecated options are kept (hidden in the UI unless current).
    self::assertSame('deprecated', $definitions['background_color']['options']['retired']['status']);
    self::assertSame('module', $definitions['spacing']['providerType']);
    self::assertSame('Canvas test styles', $definitions['spacing']['providerLabel']);
    // Join order: weight, then style ID.
    self::assertSame('spacing', \array_key_last(\array_filter($definitions, static fn ($d) => $d['provider'] === 'canvas_test_styles')));

    // SDCs accept every style by default, narrow, or opt out.
    self::assertContains('theme_mode', $this->styles->forComponent(self::component('sdc.canvas_test_styles.section')));
    self::assertSame(['background_color', 'spacing'], $this->styles->forComponent(self::component('sdc.canvas_test_styles.narrow-card')));
    self::assertSame([], $this->styles->forComponent(self::component('sdc.canvas_test_styles.divider')));
  }

  public function testCodeComponentsOptIn(): void {
    // Installed from the test module's optional config.
    $hero = JavaScriptComponent::load('canvas_test_styles_hero');
    self::assertInstanceOf(JavaScriptComponent::class, $hero);
    $props = $hero->get('props');
    $component = self::component('js.canvas_test_styles_hero');
    self::assertSame(['background_color', 'gap', 'theme_mode', 'tone', 'spacing'], $this->styles->forComponent($component));

    // `*` only covers styles a code component receives as className/style.
    $hero->set('styles', ['*'])->save();
    $accepted = $this->styles->forComponent(self::component('js.canvas_test_styles_hero'));
    self::assertContains('gap', $accepted);
    self::assertNotContains('theme_mode', $accepted);

    // Reserved prop names.
    $hero->set('props', $hero->get('props') + ['className' => ['title' => 'Class', 'type' => 'string', 'examples' => ['x']]]);
    $messages = \array_map(static fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($hero->getTypedData()->validate()));
    self::assertNotEmpty(\array_filter($messages, static fn (string $m) => str_contains($m, 'The prop "className" is reserved')));

    // Named parts, in the same shape as SDC metadata.
    $hero->set('props', $props)->set('styles', ['spacing']);
    $hero->updateFromClientSide(['styleParts' => ['title' => ['label' => 'Title', 'styles' => ['gap' => NULL, 'spacing' => ['options' => ['small']]]]]]);
    $hero->save();
    $component = self::component('js.canvas_test_styles_hero');
    self::assertSame(['spacing' => 'spacing', 'title.gap' => 'gap', 'title.spacing' => 'spacing'], $this->styles->acceptedKeys($component));
    self::assertSame(['spacing' => ['options' => ['small']]], $this->styles->partsFor($component)['title']['limits']);
    self::assertSame(['title' => ['label' => 'Title', 'styles' => ['gap', 'spacing'], 'limits' => ['spacing' => ['options' => ['small']]]]], $hero->normalizeForClientSide()->values['styleParts']);
    // `styleParts` is reserved, like `className` and `style`.
    $hero->set('props', $props + ['styleParts' => ['title' => 'Parts', 'type' => 'string', 'examples' => ['x']]]);
    $messages = \array_map(static fn ($v) => strip_tags((string) $v->getMessage()), iterator_to_array($hero->getTypedData()->validate()));
    self::assertNotEmpty(\array_filter($messages, static fn (string $m) => str_contains($m, 'The prop "styleParts" is reserved')));

    // A code component that does not opt in accepts nothing.
    $hero->set('props', $props)->set('styles', NULL)->set('style_parts', NULL)->save();
    self::assertSame([], $this->styles->forComponent(self::component('js.canvas_test_styles_hero')));
  }

  public function testEnablingStylesCreatesNoNewVersions(): void {
    $before = self::component('sdc.canvas_test_styles.section')->getVersions();
    $this->config('canvas.settings')->set('experimental.styles', FALSE)->save();
    $this->generateComponentConfig();
    $this->config('canvas.settings')->set('experimental.styles', TRUE)->save();
    $this->generateComponentConfig();
    self::assertSame($before, self::component('sdc.canvas_test_styles.section')->getVersions());
  }

  public function testValidationAndRendering(): void {
    $section = self::component('sdc.canvas_test_styles.section');
    $page = Page::create([
      'title' => 'Styled',
      'components' => [[
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'component_id' => $section->id(),
        'component_version' => $section->getActiveVersion(),
        'inputs' => ['heading' => 'Hello'],
        'styles' => [
          'spacing' => 'large',
          'background_color' => 'primary',
          'highlight' => 'yes',
          'gap' => 'loose',
          // Degraded entries save, but never render.
          'page_scheme' => 'dark',
          'alignment' => 'gone',
        ],
      ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);

    $item = $page->get('components')->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    $item->setStyles(['Bad' => '']);
    self::assertCount(1, $page->validate()->filterByFields(['path']));
    $item->setStyles(['spacing' => ['base' => 'large']]);
    self::assertStringContainsString('Per-breakpoint values are not supported yet', (string) $page->validate()->filterByFields(['path'])->get(0)->getMessage());

    $page = self::loadPage((int) $page->id());
    $build = self::tree($page)->toRenderable($page);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringContainsString('<section class="bg-primary is-highlighted p-8 styled-section" style="--gap: 2rem"', $html);
    self::assertStringNotContainsString('scheme-dark', $html);
    self::assertContains('canvas_test_styles/highlight', $build['#attached']['library'] ?? []);
    // Core's `style_plugin` tag, or the polyfill's own.
    self::assertContains($this->container->get(StyleSourceInterface::class)->cacheTag(), $build['#cache']['tags']);
    self::assertContains('config:canvas.settings', $build['#cache']['tags']);

    // Turning the setting off renders as without the feature, and keeps the
    // stored selection.
    $this->config('canvas.settings')->set('experimental.styles', FALSE)->save();
    $this->styles->reset();
    $page = self::loadPage((int) $page->id());
    $build = self::tree($page)->toRenderable($page);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringContainsString('<section data-component-id="canvas_test_styles:section" class="styled-section">', $html);
    $item = self::tree($page)->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    self::assertSame('large', $item->getStyles()['spacing']);
  }

  public function testNamedParts(): void {
    $teaser = self::component('sdc.canvas_test_styles.teaser');
    self::assertSame(['title' => [
      'label' => 'Title',
      'styles' => ['spacing', 'background_color'],
      'limits' => ['background_color' => ['colors' => ['Lagoon']], 'spacing' => ['custom' => FALSE]],
    ],
    ], $this->styles->partsFor($teaser));
    self::assertSame(['spacing' => 'spacing', 'title.spacing' => 'spacing', 'title.background_color' => 'background_color'], $this->styles->acceptedKeys($teaser));

    // Nothing stored: the part still gets its (empty) attributes.
    $page = Page::create([
      'title' => 'Parts',
      'components' => [[
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'component_id' => $teaser->id(),
        'component_version' => $teaser->getActiveVersion(),
        'inputs' => ['heading' => 'Hello'],
      ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);
    $build = self::tree($page)->toRenderable($page);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringContainsString('<h2 class="teaser__title">Hello</h2>', $html);

    // The same style on the root and on a part, each with its own value.
    $item = self::tree($page)->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    $item->setStyles([
      'spacing' => 'small',
      'title.spacing' => 'large',
      'title.background_color' => 'primary',
      // Saved, never rendered: the part doesn't accept it, or doesn't exist.
      'title.gap' => 'loose',
      'footer.spacing' => 'small',
    ]);
    self::assertSaveWithoutViolations($page);
    $page = self::loadPage((int) $page->id());
    $component = self::component('sdc.canvas_test_styles.teaser');
    self::assertEquals(['title.gap' => 'not_accepted', 'footer.spacing' => 'not_accepted'], $this->styles->degraded(self::tree($page)->get(0)->getStyles(), $component));
    $build = self::tree($page)->toRenderable($page);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringContainsString('class="p-2 teaser"', $html);
    self::assertStringContainsString('<h2 class="bg-primary p-8 teaser__title">Hello</h2>', $html);

    // Part IDs follow the part ID rule.
    self::tree($page)->get(0)->setStyles(['Title.spacing' => 'large']);
    self::assertCount(1, $page->validate()->filterByFields(['path']));
  }

  public function testTokensCustomValuesAndPartLimits(): void {
    $lagoon = Color::create([
      'name' => 'Lagoon',
      'cssVariable' => '--color-lagoon',
      'value' => ['colorSpace' => 'srgb', 'components' => [0.1, 0.4, 0.5], 'hex' => '#1a6680'],
      'weight' => 0,
    ]);
    $lagoon->save();
    $fog = Color::create([
      'name' => 'Fog',
      'cssVariable' => '--color-fog',
      'value' => ['colorSpace' => 'srgb', 'components' => [0.9, 0.9, 0.9], 'hex' => '#e6e6e6'],
      'weight' => 1,
    ]);
    $fog->save();
    $this->styles->reset();
    $lagoon_ref = Color::REFERENCE_PREFIX . $lagoon->id();
    $fog_ref = Color::REFERENCE_PREFIX . $fog->id();

    $definitions = $this->styles->definitions();
    self::assertSame('token', $definitions['background_color']['options'][$lagoon_ref]['kind']);
    self::assertSame(['px'], $definitions['spacing']['custom']['units']);

    $teaser = self::component('sdc.canvas_test_styles.teaser');
    $selection = [
      // The root takes custom values; the Title part doesn't.
      'spacing' => '=24px',
      'title.spacing' => '=8px',
      // The Title part offers Lagoon only.
      'title.background_color' => $fog_ref,
    ];
    self::assertEquals([
      'title.spacing' => 'not_allowed_here',
      'title.background_color' => 'not_allowed_here',
    ], $this->styles->degraded($selection, $teaser));
    self::assertSame(['spacing' => '=24px'], $this->styles->filter($selection, $teaser));

    $page = Page::create([
      'title' => 'Tokens',
      'components' => [[
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000003',
        'component_id' => $teaser->id(),
        'component_version' => $teaser->getActiveVersion(),
        'inputs' => ['heading' => 'Hello'],
        'styles' => ['spacing' => '=24px', 'title.background_color' => $lagoon_ref],
      ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);
    $build = self::tree($page)->toRenderable($page);
    $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
    self::assertStringContainsString('class="p-custom teaser" style="--p: 24px"', $html);
    self::assertStringContainsString('<h2 class="bg-token teaser__title" style="--bg: var(--color-lagoon, rgb(26, 102, 128))">', $html);
    // Editing any color reaches the render through the color list tag.
    self::assertContains('config:color_list', $build['#cache']['tags']);
  }

  public function testBrandKitStyleOptions(): void {
    $section = self::component('sdc.canvas_test_styles.section');
    // Canvas installs the global brand kit.
    $brand_kit = BrandKit::load(BrandKit::GLOBAL_ID);
    self::assertInstanceOf(BrandKit::class, $brand_kit);
    $messages = static fn (BrandKit $kit): array => \array_map(
      static fn ($v) => $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage()),
      iterator_to_array($kit->getTypedData()->validate()),
    );

    // Shapes are validated; references to missing styles are not.
    $brand_kit->set('styles', ['spacing' => ['options' => ['small'], 'fallbacks' => ['large' => 'medium']]]);
    self::assertSame(['styles.spacing.fallbacks.large: The fallback for "large" must be an option this site offers.'], $messages($brand_kit));
    $brand_kit->set('styles', [
      'spacing' => [
        'options' => ['small', 'medium'],
        'labels' => ['medium' => 'Regular', 'large' => 'Roomy'],
        'fallbacks' => ['large' => 'medium'],
      ],
      'background_color' => ['options' => []],
      'no_such_style' => ['options' => ['x']],
    ]);
    self::assertSame([], $messages($brand_kit));
    $brand_kit->save();

    // Effective definitions: narrowed, relabeled, with fallbacks.
    $spacing = $this->styles->definitions()['spacing'];
    self::assertSame(['small', 'medium'], \array_keys($spacing['options']));
    self::assertSame('Regular', $spacing['options']['medium']['label']);
    self::assertSame('Medium', $spacing['options']['medium']['originalLabel']);
    self::assertSame(['large' => 'Roomy'], $spacing['notOffered']);
    self::assertSame(['large' => 'medium'], $spacing['fallbacks']);
    self::assertSame(['background_color' => 'Background color'], $this->styles->hiddenStyles());
    // Acceptance is the component's own, before the brand kit.
    self::assertContains('background_color', $this->styles->forComponent($section));

    // Fallbacks render without changing stored values.
    $selection = ['spacing' => 'large', 'background_color' => 'primary'];
    self::assertSame(['spacing' => 'medium'], $this->styles->filter($selection, $section));
    self::assertSame(['spacing' => 'fallback', 'background_color' => 'not_offered'], $this->styles->degraded($selection, $section));
    self::assertContains(ComponentStyles::BRAND_KIT_CACHE_TAG, $this->styles->cacheTags([]));

    $page = Page::create([
      'title' => 'Branded',
      'components' => [[
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'component_id' => $section->id(),
        'component_version' => $section->getActiveVersion(),
        'inputs' => ['heading' => 'Hello'],
        'styles' => $selection,
      ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);
    $render = function () use ($page): string {
      $page = self::loadPage((int) $page->id());
      $build = self::tree($page)->toRenderable($page);
      $html = (string) $this->container->get(RendererInterface::class)->renderInIsolation($build);
      // Bubbled up from the component element while rendering.
      self::assertContains(ComponentStyles::BRAND_KIT_CACHE_TAG, $build['#cache']['tags']);
      return $html;
    };
    self::assertStringContainsString('<section class="p-4 styled-section"', $render());
    $item = self::tree(self::loadPage((int) $page->id()))->get(0);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    self::assertSame('large', $item->getStyles()['spacing']);

    // A brand kit draft applies to previews only.
    $draft = clone $brand_kit;
    $draft->set('styles', ['spacing' => ['options' => ['small'], 'fallbacks' => ['large' => 'small']]]);
    $this->container->get(AutoSaveManager::class)->saveEntity($draft);
    self::assertSame(['spacing' => 'medium'], $this->styles->filter($selection, $section));
    // The draft lists only spacing, so it offers background_color again.
    self::assertSame(['background_color' => 'primary', 'spacing' => 'small'], $this->styles->runInPreview(fn () => $this->styles->filter($selection, $section)));
    $page = self::loadPage((int) $page->id());
    $preview_build = self::tree($page)->toRenderable($page, TRUE);
    $preview = (string) $this->container->get(RendererInterface::class)->renderInIsolation($preview_build);
    self::assertStringContainsString('p-2 styled-section', $preview);

    // Removing the brand kit's styles offers every option again.
    $brand_kit->set('styles', [])->save();
    self::assertNull($brand_kit->get('styles'));
    self::assertStringContainsString('<section class="bg-primary p-8 styled-section"', $render());
  }

  public function testStyleLibraries(): void {
    $create = static fn (array $styles) => StyleLibrary::create(['id' => 'brand', 'label' => 'Brand', 'styles' => $styles]);
    $messages = static fn (StyleLibrary $library): array => \array_map(
      static fn ($v) => $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage()),
      iterator_to_array($library->getTypedData()->validate()),
    );

    self::assertSame(['styles.surface.options.dark.attribute: The &quot;onclick&quot; attribute is not allowed. Use class, style, data-*, or aria-*.'], $messages($create(['surface' => ['label' => 'Surface', 'options' => ['dark' => ['label' => 'Dark', 'attribute' => 'onclick']]]])));
    self::assertSame(["styles.surface.library: 'library' is not a supported key."], $messages($create(['surface' => ['label' => 'Surface', 'library' => 'core/drupal', 'options' => ['dark' => ['label' => 'Dark']]]])));
    self::assertSame(['styles.spacing: The style "spacing" is already provided by canvas_test_styles.'], $messages($create(['spacing' => ['label' => 'Spacing', 'options' => ['x' => ['label' => 'X']]]])));

    $library = $create(['surface' => ['label' => 'Surface', 'options' => ['dark' => ['label' => 'Dark', 'value' => 'bg-slate-900']]]]);
    self::assertSame([], $messages($library));
    $library->save();
    $definitions = $this->styles->definitions();
    self::assertSame('site', $definitions['surface']['providerType']);
    self::assertSame('Brand', $definitions['surface']['providerLabel']);
    self::assertContains('config:canvas.style_library.brand', $this->styles->cacheTags(['surface' => 'dark']));
    // A library style used only on a part depends on the library too.
    self::assertContains('config:canvas.style_library.brand', $this->styles->cacheTags(['heading.surface' => 'dark']));

    // Published on save.
    $styles = $library->getStyles();
    $styles['surface']['options']['dark']['value'] = 'bg-slate-950';
    $library->set('styles', $styles)->save();
    self::assertSame('bg-slate-950', $this->styles->definitions()['surface']['options']['dark']['value']);
  }

}
