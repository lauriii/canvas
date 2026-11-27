<?php

declare(strict_types=1);

namespace Drupal\canvas\Style;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\StyleLibrary;
use Drupal\canvas\Utility\ColorResolver;
use Drupal\Component\Discovery\YamlDiscovery;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * The only Canvas class that talks to the core Style API.
 *
 * A style selection is a map of style ID to option ID. Selections are stored
 * leniently (see ::validateShape()) and filtered before anything renders, so
 * other themes' styles, page-level targets, and stale entries never reach
 * markup.
 *
 * The site's brand kit narrows and relabels core's definitions: each site
 * offers its own options, and options it does not offer fall back at render.
 * Every consumer reads the result through ::definitions().
 *
 * @see https://www.drupal.org/project/drupal/issues/3517033
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 * @see docs/adr/0031-per-site-style-options-live-in-the-brand-kit.md
 *
 * @phpstan-type StyleSelection array<string, string>
 * @phpstan-type StyleOptionArray array{id: string, label: string, originalLabel: string, description: string, value: string, attribute: string, status: string, icon: ?string, kind: 'option'|'token', swatch: ?string, cssVariable: ?string, folder: ?string}
 * @phpstan-type StyleDefinitionArray array{id: string, label: string, originalLabel: string, description: string, category: string, weight: int, status: string, provider: string, providerLabel: string, providerType: string, library: string, options: array<string, StyleOptionArray>, fallbacks: array<string, string>, notOffered: array<string, string>, tokens: ?array{type: string, folders: list<string>, value: string, property: string}, custom: ?\Drupal\canvas\Style\StyleValues::CustomSpec}
 * @phpstan-type StyleLimits array{folders?: list<string>, colors?: list<string>, options?: list<string>, custom?: bool}
 * @phpstan-type BrandKitStyleEntry array{label?: string, options?: list<string>, labels?: array<string, string>, fallbacks?: array<string, string>, custom?: bool}
 * @phpstan-type ResolvedStyles array{className: ?string, style: ?array<string, string>, libraries: list<string>, attributes: list<array{attribute: string, value: string}>, parts?: array<string, array{className: ?string, style: ?array<string, string>, libraries: list<string>, attributes: list<array{attribute: string, value: string}>}>}
 * @phpstan-type StylePart array{label: string, styles: list<string>, limits: array<string, StyleLimits>}
 */
final class ComponentStyles {

  /**
   * Core's style ID rule.
   *
   * @see \Drupal\Core\Theme\Style\StylePluginManager::processDefinition()
   */
  public const string STYLE_ID_PATTERN = '/^[a-z0-9_]+$/';

  /**
   * Core's option ID rule.
   *
   * @see core/assets/schemas/v1/style.schema.json
   */
  public const string OPTION_ID_PATTERN = '/^(?!0$)[a-zA-Z0-9_ -]+$/';

  /**
   * The value of a Component's `styles` setting that accepts every style.
   */
  public const string ALL = '*';

  /**
   * Separates a named part from the style ID in a selection key:
   * `heading.text_size` is Text size on the component's Heading part.
   *
   * @see docs/adr/0033-style-parts.md
   */
  public const string PART_SEPARATOR = '.';

  /**
   * A named part's ID. It becomes a Twig variable: `{part}_attributes`.
   */
  public const string PART_ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

  /**
   * The provider of Canvas-managed style libraries.
   */
  public const string LIBRARY_PROVIDER = 'canvas';

  /**
   * The style definition files core discovers: utilities and themes.
   *
   * @see \Drupal\Core\Theme\Style\Enum\Type::discoveryName()
   */
  private const array DISCOVERY_NAMES = ['utilities', 'themes'];

  /**
   * The brand kit holds which options this site offers.
   */
  public const string BRAND_KIT_CACHE_TAG = 'config:canvas.brand_kit.' . BrandKit::GLOBAL_ID;

  /**
   * Attributes a code component can receive through `className` and `style`.
   */
  private const array CODE_COMPONENT_ATTRIBUTES = ['class', 'style'];

  /**
   * Available definitions before the brand kit, built once per request.
   *
   * @var array<string, StyleDefinitionArray>|null
   */
  private ?array $available = NULL;

  /**
   * Canvas extension declarations, built once per request.
   *
   * @var array<string, array{tokens?: mixed, custom?: mixed}>|null
   */
  private ?array $extensions = NULL;

  /**
   * Definitions after the brand kit, built once per request and mode.
   *
   * @var array<string, StyleDefinitionArray>|null
   */
  private ?array $effective = NULL;

  /**
   * Styles the brand kit does not offer at all: style ID to label.
   *
   * @var array<string, string>
   */
  private array $hidden = [];

  /**
   * Whether to read the draft brand kit, as editor previews do.
   */
  private bool $preview = FALSE;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    // TRICKY: the core Style API is not committed yet. With it, Canvas uses
    // CoreStyleSource; without it, the canvas_style_polyfill module provides
    // a source; with neither, there is none and styles are inactive.
    // @see \Drupal\canvas\CanvasServiceProvider::alter()
    private readonly ?StyleSourceInterface $source = NULL,
  ) {}

  /**
   * Whether styles are active: a source exists and the flag is on.
   */
  public function isActive(): bool {
    return $this->source !== NULL
      && (bool) $this->configFactory->get('canvas.settings')->get('experimental.styles');
  }

  /**
   * Whether core provides the Style API, regardless of the experimental flag.
   */
  public function coreHasStyleApi(): bool {
    return $this->source?->isCore() ?? FALSE;
  }

  /**
   * Whether any source provides definitions: core or the polyfill.
   */
  public function hasStyleSource(): bool {
    return $this->source !== NULL;
  }

  /**
   * Definitions as the source provides them, before Canvas filters them.
   *
   * @return array<string, \Drupal\canvas\Style\StyleSourceInterface::SourceDefinition>
   */
  public function sourceDefinitions(): array {
    return $this->source?->definitions() ?? [];
  }

  /**
   * Lists available styles, normalized for Canvas.
   *
   * A style is available when it is enabled and its provider is a module, the
   * default theme or one of its base themes, or a Canvas-managed library. Only
   * options targeting `any` that are not `obsolete` are kept.
   *
   * This is before the brand kit applies; use ::definitions() everywhere else.
   *
   * @return array<string, StyleDefinitionArray>
   *   Keyed by style ID, sorted in the order classes are joined: weight, then
   *   style ID.
   */
  public function availableDefinitions(): array {
    if ($this->available !== NULL) {
      return $this->available;
    }
    if (!$this->isActive() || $this->source === NULL) {
      return $this->available = [];
    }
    $themes = $this->defaultThemeWithBaseThemes();
    $available = [];
    foreach ($this->source->definitions() as $id => $definition) {
      $provider = $definition['provider'];
      $provider_type = match (TRUE) {
        $provider === self::LIBRARY_PROVIDER && $definition['canvas_library'] !== NULL => 'site',
        \in_array($provider, $themes, TRUE) => 'theme',
        $this->themeHandler->themeExists($provider) => NULL,
        default => 'module',
      };
      if ($provider_type === NULL || !$definition['enabled']) {
        continue;
      }
      $options = [];
      foreach ($definition['options'] as $option_id => $option) {
        if ($option['target'] !== 'any' || $option['status'] === 'obsolete') {
          continue;
        }
        $options[(string) $option_id] = [
          'id' => (string) $option_id,
          'label' => $option['label'],
          'originalLabel' => $option['label'],
          'description' => $option['description'],
          'value' => $option['value'],
          'attribute' => $option['attribute'],
          'status' => $option['status'],
          'icon' => $option['icon'],
          'kind' => 'option',
          'swatch' => NULL,
          'cssVariable' => NULL,
          'folder' => NULL,
        ];
      }
      // Canvas extensions: from a style library, or a theme's or module's
      // `{provider}.canvas.styles.yml`.
      // @see docs/adr/0032-style-options-can-come-from-design-tokens.md
      $extension = $this->extensionDeclarations()[(string) $id] ?? [];
      $tokens = self::normalizeTokens($definition['tokens'] ?? $extension['tokens'] ?? NULL);
      $custom = StyleValues::normalizeCustom($definition['custom'] ?? $extension['custom'] ?? NULL);
      if ($tokens !== NULL) {
        $options += $this->tokenOptions($tokens);
      }
      if ($options === [] && $custom === NULL) {
        continue;
      }
      $available[(string) $id] = [
        'id' => (string) $id,
        'label' => $definition['label'],
        'originalLabel' => $definition['label'],
        'description' => $definition['description'],
        'category' => $definition['category'],
        'weight' => $definition['weight'],
        'status' => $definition['status'],
        'provider' => $provider,
        'providerLabel' => $this->providerLabel($provider, $provider_type, $definition['canvas_library']),
        'providerType' => $provider_type,
        'library' => $definition['library'],
        'options' => $options,
        'fallbacks' => [],
        'notOffered' => [],
        'tokens' => $tokens,
        'custom' => $custom,
      ];
    }
    \uasort($available, static fn (array $a, array $b): int => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
    return $this->available = $available;
  }

  /**
   * The human-readable name of a style's provider.
   *
   * @param string $provider
   *   The extension machine name.
   * @param string $provider_type
   *   One of 'site', 'theme', or 'module'.
   * @param mixed $library_id
   *   The style library ID, for site styles.
   */
  private function providerLabel(string $provider, string $provider_type, mixed $library_id): string {
    return match ($provider_type) {
      'site' => (string) ($this->configFactory->get(StyleLibrary::CONFIG_PREFIX . $library_id)->get('label') ?: $library_id),
      'theme' => $this->themeHandler->getName($provider),
      default => $this->moduleList->exists($provider) ? $this->moduleList->getName($provider) : $provider,
    };
  }

  /**
   * Lists the styles this site offers, after the brand kit.
   *
   * The brand kit drops options the site does not offer (recorded in
   * `notOffered`), applies label overrides (`originalLabel` keeps core's), and
   * keeps only fallbacks that point at an offered option. A style left with no
   * options is not offered at all; see ::hiddenStyles().
   *
   * @return array<string, StyleDefinitionArray>
   *   Keyed by style ID, in join order.
   */
  public function definitions(): array {
    if ($this->effective !== NULL) {
      return $this->effective;
    }
    $brand_styles = $this->brandKitStyles();
    $effective = [];
    $this->hidden = [];
    foreach ($this->availableDefinitions() as $id => $definition) {
      $entry = $brand_styles[$id] ?? NULL;
      if (\is_array($entry)) {
        if (isset($entry['label']) && $entry['label'] !== '') {
          $definition['label'] = $entry['label'];
        }
        if (isset($entry['options'])) {
          foreach ($definition['options'] as $option_id => $option) {
            // Brand kit colors are curated in the brand kit itself: `options`
            // narrows fixed options only.
            if ($option['kind'] === 'token') {
              continue;
            }
            if (!\in_array((string) $option_id, $entry['options'], TRUE)) {
              // The site's own name for it, if it had one.
              $definition['notOffered'][(string) $option_id] = (string) ($entry['labels'][$option_id] ?? '') ?: $option['originalLabel'];
              unset($definition['options'][$option_id]);
            }
          }
        }
        foreach ($entry['labels'] ?? [] as $option_id => $label) {
          if (isset($definition['options'][$option_id]) && $label !== '') {
            $definition['options'][$option_id]['label'] = $label;
          }
        }
        foreach ($entry['fallbacks'] ?? [] as $from => $to) {
          if (isset($definition['options'][$to]) && !isset($definition['options'][$from])) {
            $definition['fallbacks'][(string) $from] = $to;
          }
        }
        // A site can turn off custom values for a style.
        if (($entry['custom'] ?? TRUE) === FALSE) {
          $definition['custom'] = NULL;
        }
      }
      if ($definition['options'] === [] && $definition['custom'] === NULL) {
        $this->hidden[$id] = $definition['label'];
        continue;
      }
      $effective[$id] = $definition;
    }
    return $this->effective = $effective;
  }

  /**
   * Styles the brand kit does not offer at all, with their labels.
   *
   * @return array<string, string>
   */
  public function hiddenStyles(): array {
    $this->definitions();
    return $this->hidden;
  }

  /**
   * The brand kit's `styles`: the draft in preview mode, else the published.
   *
   * @return array<string, BrandKitStyleEntry>
   */
  public function brandKitStyles(): array {
    $brand_kit = $this->entityTypeManager->getStorage(BrandKit::ENTITY_TYPE_ID)->load(BrandKit::GLOBAL_ID);
    if (!$brand_kit instanceof BrandKit) {
      return [];
    }
    if ($this->preview) {
      $draft = $this->autoSaveManager->getAutoSaveEntity($brand_kit)->entity;
      if ($draft instanceof BrandKit) {
        $brand_kit = $draft;
      }
    }
    return $brand_kit->getStyleOptions();
  }

  /**
   * Runs a callback with the draft brand kit applied, as editor previews do.
   *
   * @param callable(): T $callback
   *   The work to run with the draft brand kit.
   *
   * @return T
   *   What the callback returns.
   *
   * @template T
   */
  public function runInPreview(callable $callback): mixed {
    $previous = $this->preview;
    $this->preview = TRUE;
    $this->effective = NULL;
    try {
      return $callback();
    }
    finally {
      $this->preview = $previous;
      $this->effective = NULL;
    }
  }

  /**
   * Whether the draft brand kit applies.
   */
  public function isPreview(): bool {
    return $this->preview;
  }

  /**
   * Resets the static cache, for example after a style library is saved.
   */
  public function reset(): void {
    $this->available = NULL;
    $this->effective = NULL;
    $this->extensions = NULL;
  }

  /**
   * The raw `styles` setting: '*' or a list of style IDs.
   *
   * @return '*'|list<string>
   */
  public static function acceptedSetting(ComponentInterface $component): string|array {
    $settings = $component->getSettings();
    if (\array_key_exists('styles', $settings) && \is_array($settings['styles'])) {
      return $settings['styles'] === [self::ALL] ? self::ALL : \array_values($settings['styles']);
    }
    return self::defaultSetting();
  }

  /**
   * The `styles` setting a Component gets when it does not declare one.
   *
   * Every component opts in: a component that does not list styles shows no
   * Style tab, so editors only see styles its author chose.
   *
   * @return list<string>
   */
  public static function defaultSetting(): array {
    return [];
  }

  /**
   * Lists the available styles the loaded version of a Component accepts.
   *
   * Acceptance is the component's own declaration, before the brand kit: the
   * site's offered options apply on top, in ::filter() and the Style tab, so
   * a brand kit draft that offers a style again shows it right away.
   *
   * @return list<string>
   *   Style IDs, in join order.
   */
  public function forComponent(ComponentInterface $component): array {
    $setting = self::acceptedSetting($component);
    $is_code_component = $component->get('source') === 'js';
    $accepted = [];
    foreach ($this->availableDefinitions() as $id => $definition) {
      if ($setting === self::ALL) {
        // For code components, `*` only covers styles they can receive as
        // `className` and `style`. Others need code, so they must be listed.
        if ($is_code_component && !self::onlySetsClassOrStyle($definition)) {
          continue;
        }
        $accepted[] = $id;
      }
      elseif (\in_array($id, $setting, TRUE)) {
        $accepted[] = $id;
      }
    }
    return $accepted;
  }

  /**
   * The named parts a Component declares, each with the available styles it
   * accepts, in declaration order.
   *
   * A part is an element inside the component, such as its heading, that
   * takes styles of its own. The same style can be accepted by the root and
   * by several parts, each with its own value.
   *
   * @return array<string, StylePart>
   *   Keyed by part ID.
   */
  public function partsFor(ComponentInterface $component): array {
    $declared = $component->getSettings()['style_parts'] ?? [];
    if (!\is_array($declared)) {
      return [];
    }
    $available = $this->availableDefinitions();
    $parts = [];
    foreach ($declared as $part => $definition) {
      $part = (string) $part;
      if (!\is_array($definition) || !preg_match(self::PART_ID_PATTERN, $part)) {
        continue;
      }
      $parts[$part] = [
        'label' => (string) ($definition['label'] ?? $part),
        'styles' => \array_values(\array_filter(
          (array) ($definition['styles'] ?? []),
          static fn (mixed $id): bool => \is_string($id) && isset($available[$id]),
        )),
        // Each part narrows its styles on its own.
        'limits' => StyleDeclaration::limits($component, $part),
      ];
    }
    return $parts;
  }

  /**
   * Every selection key a Component accepts: root style IDs, then each part's
   * `part.style_id` keys.
   *
   * @return array<string, string>
   *   Style IDs, keyed by selection key.
   */
  public function acceptedKeys(ComponentInterface $component): array {
    $root = $this->forComponent($component);
    $keys = $root === [] ? [] : \array_combine($root, $root);
    foreach ($this->partsFor($component) as $part => ['styles' => $styles]) {
      foreach ($styles as $style_id) {
        $keys[$part . self::PART_SEPARATOR . $style_id] = $style_id;
      }
    }
    return $keys;
  }

  /**
   * Splits a selection key into its part (NULL for the root) and style ID.
   *
   * @return array{0: ?string, 1: string}
   */
  public static function splitKey(string $key): array {
    $position = strpos($key, self::PART_SEPARATOR);
    return $position === FALSE
      ? [NULL, $key]
      : [substr($key, 0, $position), substr($key, $position + 1)];
  }

  /**
   * Whether every option of a normalized definition sets `class` or `style`.
   */
  public static function onlySetsClassOrStyle(array $definition): bool {
    foreach ($definition['options'] as $option) {
      if (!\in_array($option['attribute'], self::CODE_COMPONENT_ATTRIBUTES, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Validates the shape of a stored selection.
   *
   * Only shape errors are rejected: unavailable or unaccepted entries are
   * preserved, skipped at render, and reported.
   *
   * @return list<array{key: ?string, message: string}>
   *   Errors. Empty when the shape is valid.
   */
  public static function validateShape(mixed $styles): array {
    if ($styles === NULL || $styles === []) {
      return [];
    }
    if (!\is_array($styles) || array_is_list($styles)) {
      return [['key' => NULL, 'message' => 'Styles must be a map of style ID to option ID.']];
    }
    $errors = [];
    foreach ($styles as $style_id => $option_id) {
      $style_id = (string) $style_id;
      [$part, $bare_id] = self::splitKey($style_id);
      if (!preg_match(self::STYLE_ID_PATTERN, $bare_id) || ($part !== NULL && !preg_match(self::PART_ID_PATTERN, $part))) {
        $errors[] = [
          'key' => $style_id,
          'message' => \sprintf('"%s" is not a valid style ID. Style IDs contain only lowercase letters, numbers, and underscores, optionally after a part ID and a dot.', $style_id),
        ];
        continue;
      }
      if (\is_array($option_id)) {
        $errors[] = [
          'key' => $style_id,
          'message' => \sprintf('Per-breakpoint values are not supported yet. Set "%s" to a single option ID.', $style_id),
        ];
        continue;
      }
      if (!\is_string($option_id) || !StyleValues::hasValidShape($option_id)) {
        $errors[] = [
          'key' => $style_id,
          'message' => \sprintf('The option for "%s" is not a valid option ID.', $style_id),
        ];
      }
    }
    return $errors;
  }

  /**
   * Keeps the entries that are available and accepted by the loaded version.
   *
   * @param array<string, mixed> $selection
   *   A stored selection.
   *
   * @return StyleSelection
   *   The filtered selection, in join order.
   */
  public function filter(array $selection, ComponentInterface $component): array {
    if ($selection === [] || !$this->isActive()) {
      return [];
    }
    $definitions = $this->definitions();
    $filtered = [];
    // Root styles and part styles alike; each element has its own limits.
    foreach ($this->acceptedKeys($component) as $key => $style_id) {
      $option_id = $selection[$key] ?? NULL;
      $definition = $definitions[$style_id] ?? NULL;
      if (!\is_string($option_id) || $definition === NULL) {
        continue;
      }
      $limit = self::limitFor($component, $key);
      if (StyleValues::isCustom($option_id)) {
        if (self::customAllowed($definition, $limit) && StyleValues::parseCustom($option_id, $definition['custom']) !== NULL) {
          $filtered[$key] = $option_id;
        }
      }
      elseif (isset($definition['options'][$option_id])) {
        if (self::allowedHere($definition['options'][$option_id], $limit)) {
          $filtered[$key] = $option_id;
        }
      }
      // One fallback step: the stored value stays, the site's option renders.
      elseif (isset($definition['fallbacks'][$option_id]) && self::allowedHere($definition['options'][$definition['fallbacks'][$option_id]], $limit)) {
        $filtered[$key] = $definition['fallbacks'][$option_id];
      }
    }
    return $filtered;
  }

  /**
   * Lists stored entries that do not render as stored, with the reason.
   *
   * - `fallback`: not offered on this site; its fallback renders instead.
   * - `not_offered`: the brand kit does not offer it, and there is no
   *   fallback.
   * - `unavailable`: no installed extension provides it.
   * - `not_accepted`: this version of the component does not accept it.
   *
   * - `not_allowed_here`: this component limits the style to other choices.
   * - `invalid`: a custom value outside the style's units or range.
   *
   * @return array<string, 'fallback'|'not_offered'|'unavailable'|'not_accepted'|'not_allowed_here'|'invalid'>
   *   Keyed by selection key: a style ID, or `part.style_id`.
   */
  public function degraded(array $selection, ComponentInterface $component): array {
    if ($selection === [] || !$this->isActive()) {
      return [];
    }
    $definitions = $this->definitions();
    $hidden = $this->hiddenStyles();
    $accepted = $this->acceptedKeys($component);
    $degraded = [];
    foreach ($selection as $key => $option_id) {
      $key = (string) $key;
      [, $style_id] = self::splitKey($key);
      $definition = $definitions[$style_id] ?? NULL;
      if (!\is_string($option_id)) {
        $degraded[$key] = 'unavailable';
      }
      elseif ($definition === NULL) {
        $degraded[$key] = isset($hidden[$style_id]) ? 'not_offered' : 'unavailable';
      }
      elseif (!isset($accepted[$key])) {
        $degraded[$key] = 'not_accepted';
      }
      elseif (StyleValues::isCustom($option_id)) {
        $limit = self::limitFor($component, $key);
        if (!self::customAllowed($definition, $limit)) {
          $degraded[$key] = $definition['custom'] === NULL ? 'not_offered' : 'not_allowed_here';
        }
        elseif (StyleValues::parseCustom($option_id, $definition['custom']) === NULL) {
          $degraded[$key] = 'invalid';
        }
      }
      elseif (isset($definition['options'][$option_id]) && !self::allowedHere($definition['options'][$option_id], self::limitFor($component, $key))) {
        $degraded[$key] = 'not_allowed_here';
      }
      elseif (!isset($definition['options'][$option_id])) {
        $degraded[$key] = match (TRUE) {
          isset($definition['fallbacks'][$option_id]) => 'fallback',
          isset($definition['notOffered'][$option_id]) => 'not_offered',
          default => 'unavailable',
        };
      }
    }
    return $degraded;
  }

  /**
   * Resolves a filtered selection to the values a code component receives.
   *
   * @param StyleSelection $filtered
   *   A selection returned by ::filter().
   *
   * @return ResolvedStyles
   *   `className` joins class values in style weight order, then style ID
   *   order. `style` is parsed into an object with custom properties kept.
   *   Both are NULL when empty. `attributes` lists each option's attribute
   *   and value in the same order, for render arrays.
   *
   * @see packages/drupal-canvas/src/styles.tsx
   */
  public function resolve(array $filtered): array {
    $definitions = $this->definitions();
    $root = [];
    $parts = [];
    foreach ($filtered as $key => $option_id) {
      [$part, $style_id] = self::splitKey((string) $key);
      if ($part === NULL) {
        $root[$style_id] = $option_id;
      }
      else {
        $parts[$part][$style_id] = $option_id;
      }
    }
    // Code components receive the root's className and style; parts render
    // through SDC templates only.
    $resolved = self::resolveWithDefinitions($root, $definitions);
    $resolved['parts'] = \array_map(
      static fn (array $selection): array => self::resolveWithDefinitions($selection, $definitions),
      $parts,
    );
    return $resolved;
  }

  /**
   * Resolves a selection against given normalized definitions.
   *
   * @return ResolvedStyles
   */
  public static function resolveWithDefinitions(array $selection, array $definitions): array {
    $classes = [];
    $style = [];
    $style_parts = [];
    $libraries = [];
    $attributes = [];
    foreach ($definitions as $style_id => $definition) {
      $selected = $selection[$style_id] ?? NULL;
      if (!\is_string($selected)) {
        continue;
      }
      // Each choice contributes at most one value for one attribute, plus
      // inline declarations, which are combined into one `style` at the end.
      $attribute = NULL;
      $value = '';
      $declarations = [];
      if (StyleValues::isCustom($selected)) {
        $parsed = isset($definition['custom']) ? StyleValues::parseCustom($selected, $definition['custom']) : NULL;
        if ($parsed === NULL) {
          continue;
        }
        [$attribute, $value] = ['class', $definition['custom']['value']];
        $declarations[$definition['custom']['property']] = $parsed['css'];
      }
      else {
        $option = $definition['options'][$selected] ?? NULL;
        if ($option === NULL) {
          continue;
        }
        if (($option['kind'] ?? 'option') === 'token' && isset($definition['tokens'])) {
          [$attribute, $value] = ['class', $definition['tokens']['value']];
          // Built from the brand kit color, never from the stored reference.
          // The color's value is the fallback where brand kit CSS is absent.
          $declarations[$definition['tokens']['property']] = \sprintf('var(%s, %s)', $option['cssVariable'], $option['swatch']);
        }
        elseif ($option['attribute'] === 'style') {
          $declarations += self::parseDeclarations($option['value']);
        }
        else {
          [$attribute, $value] = [$option['attribute'], trim($option['value'])];
        }
      }
      if ($definition['library'] !== '') {
        $libraries[] = $definition['library'];
      }
      // An empty value is logic-only: read through useStyleOptions(), no
      // markup.
      if ($attribute !== NULL && $value !== '') {
        $attributes[] = ['attribute' => $attribute, 'value' => $value];
        if ($attribute === 'class') {
          $classes[] = $value;
        }
      }
      foreach ($declarations as $property => $declaration) {
        if (!isset($style[$property])) {
          $style[$property] = $declaration;
          $style_parts[] = $property . ': ' . $declaration;
        }
      }
    }
    if ($style_parts !== []) {
      $attributes[] = ['attribute' => 'style', 'value' => implode('; ', $style_parts)];
    }
    // React expects camelCase for standard properties, and keeps custom
    // properties as they are.
    $style = self::parseInlineStyle(implode('; ', $style_parts));
    $class_name = trim(implode(' ', $classes));
    return [
      'className' => $class_name === '' ? NULL : $class_name,
      'style' => $style === [] ? NULL : $style,
      'libraries' => \array_values(\array_unique($libraries)),
      'attributes' => $attributes,
    ];
  }

  /**
   * Parses an inline style declaration list into a property map.
   *
   * Ponytail: splits on `;` and the first `:`, so a `;` inside `url()` or a
   * string breaks the declaration. Use a CSS parser if such values appear.
   *
   * @return array<string, string>
   */
  public static function parseInlineStyle(string $value): array {
    $parsed = [];
    foreach (self::parseDeclarations($value) as $property => $declaration) {
      // React expects camelCase for standard properties and keeps custom
      // properties as-is.
      if (!str_starts_with($property, '--')) {
        $property = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', strtolower($property)))));
      }
      $parsed[$property] = $declaration;
    }
    return $parsed;
  }

  /**
   * Parses an inline style declaration list, keeping CSS property names.
   *
   * @return array<string, string>
   */
  private static function parseDeclarations(string $value): array {
    $parsed = [];
    foreach (explode(';', $value) as $declaration) {
      $parts = explode(':', $declaration, 2);
      if (\count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
        continue;
      }
      $parsed[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return $parsed;
  }

  /**
   * Cache tags for output of an instance with stored styles.
   *
   * @return list<string>
   */
  public function cacheTags(array $filtered): array {
    // Whether stored styles render depends on the experimental setting, the
    // installed extensions, and the default theme, even when none render now.
    $tags = [
      ...$this->definitionsCacheability()['tags'],
      'config:canvas.settings',
      'config:core.extension',
      'config:system.theme',
      self::BRAND_KIT_CACHE_TAG,
    ];
    if ($this->preview) {
      $tags[] = AutoSaveManager::CACHE_TAG;
    }
    // Part keys (`part.style_id`) use the same library as the style.
    $used = \array_flip(\array_map(static fn (string|int $key): string => self::splitKey((string) $key)[1], \array_keys($filtered)));
    foreach ($this->sourceDefinitions() as $style_id => $definition) {
      if (isset($used[$style_id]) && $definition['canvas_library'] !== NULL) {
        $tags[] = 'config:' . StyleLibrary::CONFIG_PREFIX . $definition['canvas_library'];
      }
    }
    return \array_values(\array_unique($tags));
  }

  /**
   * Cache tags and contexts for the list of available definitions.
   *
   * @return array{tags: list<string>, contexts: list<string>}
   */
  public function definitionsCacheability(): array {
    return [
      'tags' => [
        $this->source?->cacheTag() ?? 'style_plugin',
        'config:core.extension',
        'config:system.theme',
        'config:canvas.settings',
        'config:' . StyleLibrary::CONFIG_PREFIX . 'list',
        self::BRAND_KIT_CACHE_TAG,
        // Brand kit colors are options of token-backed styles.
        'config:color_list',
        'config:folder_list',
      ],
      'contexts' => ['languages:language_interface'],
    ];
  }

  /**
   * Clears cached definitions, for example after a library is saved.
   */
  public function clearCachedDefinitions(): void {
    $this->source?->clearCachedDefinitions();
    $this->reset();
  }

  /**
   * Canvas extensions to core styles, from `{provider}.canvas.styles.yml`.
   *
   * Core's style schema rejects unknown keys, so themes and modules declare
   * `tokens` and `custom` here, keyed by style ID. Later providers win, as in
   * core. Style libraries declare them on the style itself.
   *
   * @return array<string, array{tokens?: mixed, custom?: mixed}>
   */
  public function extensionDeclarations(): array {
    if ($this->extensions !== NULL) {
      return $this->extensions;
    }
    $directories = $this->moduleHandler->getModuleDirectories() + $this->themeHandler->getThemeDirectories();
    $declarations = [];
    foreach ((new YamlDiscovery('canvas.styles', $directories))->findAll() as $styles) {
      foreach ((array) $styles as $style_id => $declaration) {
        if (\is_array($declaration)) {
          $declarations[(string) $style_id] = \array_intersect_key($declaration, ['tokens' => TRUE, 'custom' => TRUE]);
        }
      }
    }
    return $this->extensions = $declarations;
  }

  /**
   * Normalizes a `tokens` declaration, or NULL when it is unusable.
   *
   * @return array{type: string, folders: list<string>, value: string, property: string}|null
   */
  private static function normalizeTokens(mixed $tokens): ?array {
    if (!\is_array($tokens) || ($tokens['type'] ?? 'color') !== 'color') {
      return NULL;
    }
    $value = trim((string) ($tokens['value'] ?? ''));
    $property = (string) ($tokens['property'] ?? '');
    if ($value === '' || !preg_match(StyleValues::CUSTOM_PROPERTY_PATTERN, $property)) {
      return NULL;
    }
    return [
      'type' => 'color',
      'folders' => \array_values(\array_map('strval', (array) ($tokens['folders'] ?? []))),
      'value' => $value,
      'property' => $property,
    ];
  }

  /**
   * One option per brand kit color the token source allows.
   *
   * @param array{type: string, folders: list<string>, value: string, property: string} $tokens
   *
   * @return array<string, StyleOptionArray>
   */
  private function tokenOptions(array $tokens): array {
    $folders = $this->colorFolders();
    $options = [];
    foreach ($this->entityTypeManager->getStorage(Color::ENTITY_TYPE_ID)->loadMultiple() as $color) {
      \assert($color instanceof Color);
      $folder = $folders[$color->id()] ?? NULL;
      if ($tokens['folders'] !== [] && !self::inFolders($folder, $tokens['folders'])) {
        continue;
      }
      // The variable name reaches markup, so it is checked like a declared
      // custom property.
      if (!preg_match(StyleValues::CUSTOM_PROPERTY_PATTERN, $color->getCssVariable())) {
        continue;
      }
      $reference = Color::REFERENCE_PREFIX . $color->id();
      $options[$reference] = [
        'id' => $reference,
        'label' => $color->getName(),
        'originalLabel' => $color->getName(),
        'description' => '',
        'value' => '',
        'attribute' => 'class',
        'status' => 'stable',
        'icon' => NULL,
        'kind' => 'token',
        'swatch' => ColorResolver::computeCssColorValue($color->getValue()),
        'cssVariable' => $color->getCssVariable(),
        'folder' => $folder,
      ];
    }
    return $options;
  }

  /**
   * Color folder names, keyed by color ID.
   *
   * @return array<string, string>
   */
  private function colorFolders(): array {
    $names = [];
    foreach ($this->entityTypeManager->getStorage(Folder::ENTITY_TYPE_ID)->loadByProperties(['configEntityTypeId' => Color::ENTITY_TYPE_ID]) as $folder) {
      \assert($folder instanceof Folder);
      foreach ($folder->get('items') as $item) {
        $names[(string) $item] = (string) $folder->get('name');
      }
    }
    return $names;
  }

  /**
   * Whether a folder name is in a list of names, case-insensitively.
   *
   * @param list<string> $names
   */
  private static function inFolders(?string $folder, array $names): bool {
    return $folder !== NULL && \in_array(mb_strtolower($folder), \array_map('mb_strtolower', $names), TRUE);
  }

  /**
   * A component's per-style limits, from its `style_limits` setting.
   *
   * @return array<string, StyleLimits>
   */
  public static function limitsFor(ComponentInterface $component): array {
    return StyleDeclaration::limits($component);
  }

  /**
   * The limit for a selection key: the root's, or its part's.
   *
   * @return StyleLimits|null
   */
  public static function limitFor(ComponentInterface $component, string $key): ?array {
    [$part, $style_id] = self::splitKey($key);
    return StyleDeclaration::limits($component, $part)[$style_id] ?? NULL;
  }

  /**
   * Whether a component's limit for a style allows an option.
   *
   * A limit narrows only what it names: `options` for fixed options,
   * `folders` and `colors` (by name) for brand kit colors.
   *
   * @param StyleOptionArray $option
   * @param StyleLimits|null $limit
   */
  private static function allowedHere(array $option, ?array $limit): bool {
    if ($limit === NULL) {
      return TRUE;
    }
    if ($option['kind'] === 'token') {
      $folders = $limit['folders'] ?? NULL;
      $colors = $limit['colors'] ?? NULL;
      if ($folders === NULL && $colors === NULL) {
        return TRUE;
      }
      return self::inFolders($option['folder'], $folders ?? [])
        || \in_array(mb_strtolower($option['label']), \array_map('mb_strtolower', $colors ?? []), TRUE);
    }
    return !isset($limit['options']) || \in_array($option['id'], $limit['options'], TRUE);
  }

  /**
   * Whether custom values are allowed for a style on a component.
   *
   * @param StyleDefinitionArray $definition
   * @param StyleLimits|null $limit
   */
  private static function customAllowed(array $definition, ?array $limit): bool {
    return $definition['custom'] !== NULL && ($limit['custom'] ?? TRUE) !== FALSE;
  }

  /**
   * Lists style IDs that more than one extension defines.
   *
   * Core keeps one definition per ID and silently replaces earlier ones
   * (modules first, then themes), so the losing definition never reaches
   * Canvas. Data health reports these.
   *
   * @return array<string, array{providers: list<string>, kept: string}>
   *   Keyed by style ID.
   */
  public function duplicateDefinitions(): array {
    if ($this->source === NULL) {
      return [];
    }
    $directories = $this->moduleHandler->getModuleDirectories() + $this->themeHandler->getThemeDirectories();
    $providers = [];
    foreach (self::DISCOVERY_NAMES as $name) {
      // The raw per-provider files, before they are flattened by ID.
      foreach ((new YamlDiscovery($name, $directories))->findAll() as $provider => $definitions) {
        foreach (\array_keys((array) $definitions) as $id) {
          $providers[(string) $id][] = (string) $provider;
        }
      }
    }
    $kept = $this->sourceDefinitions();
    $duplicates = [];
    foreach ($providers as $id => $list) {
      $list = \array_values(\array_unique($list));
      if (\count($list) > 1) {
        $duplicates[$id] = [
          'providers' => $list,
          'kept' => isset($kept[$id]) ? $kept[$id]['provider'] : (string) \end($list),
        ];
      }
    }
    return $duplicates;
  }

  /**
   * @return list<string>
   */
  private function defaultThemeWithBaseThemes(): array {
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    $themes = $this->themeHandler->listInfo();
    if (!isset($themes[$default])) {
      return [];
    }
    // @phpstan-ignore property.notFound
    $base_themes = (array) ($themes[$default]->base_themes ?? []);
    return [...\array_map('strval', \array_keys($base_themes)), $default];
  }

}
