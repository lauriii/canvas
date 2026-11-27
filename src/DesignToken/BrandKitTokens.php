<?php

declare(strict_types=1);

namespace Drupal\canvas\DesignToken;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\DesignToken\DesignToken as DesignTokenDefinition;
use Drupal\Core\Theme\DesignToken\Group;
use Drupal\Core\Theme\Entity\DesignToken;
use Drupal\Core\Utility\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The only Canvas class that calls core's design token API.
 *
 * Core's design token classes are `@internal` and experimental. Keeping every
 * call in this one class isolates Canvas from changes to them.
 *
 * Theme tokens are the definitions of the default theme, its base themes and
 * enabled modules, of the types Canvas can edit. Site builders override their
 * `:root` value; overrides are core `design_token` config entities.
 *
 * The feature is opt-in: it is active only when core has the API, a `color`
 * value plugin exists (core's or Canvas's polyfill), and the
 * `canvas.settings:experimental_design_tokens` setting is on.
 *
 * Also:
 * - invalidates caches and regenerates the brand kit stylesheet when the
 *   setting changes (config save event), and
 * - blocks uninstalling Canvas while `color` tokens depend on the polyfill.
 *
 * @phpstan-type TokenValue mixed
 * @phpstan-type ValidationErrors array<string, string>
 *
 * @internal
 */
final class BrandKitTokens implements EventSubscriberInterface, ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public const string SETTING = 'experimental_design_tokens';

  /**
   * Invalidated whenever the setting changes.
   */
  public const string MODE_CACHE_TAG = 'canvas_design_tokens_mode';

  public const string STAGED_ID_PREFIX = 'canvas_design_token__';

  public const string CONFIG_ACTION = 'canvasDesignTokenValue';

  /**
   * The token types Canvas lists: every type core can serialize, plus color.
   */
  public const array TYPES = ['color', 'fontFamily', 'fontWeight', 'dimension', 'number', 'typography'];

  /**
   * The `core.design_token.*:path` pattern.
   */
  private const string STORABLE_PATH = '/^[a-z0-9_\s\-\.]+$/';

  /**
   * CSS identifiers separated by single spaces. Generic families match too.
   */
  private const string FONT_FAMILY = '/^-?[A-Za-z_][A-Za-z0-9_-]*( -?[A-Za-z_][A-Za-z0-9_-]*)*$/';

  private const string ROOT = ':root';

  /**
   * @var array<string, \Drupal\Core\Theme\DesignToken\DesignToken>|null
   */
  private ?array $listed = NULL;

  /**
   * @var array<string, \Drupal\Core\Theme\Entity\DesignToken>|null
   */
  private ?array $byPath = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly AutoSaveManager $autoSaveManager,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
    // Core's design token plugin managers exist only on core 11.5 or later.
    #[Autowire(service: 'service_container')]
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Core's value plugin manager, or NULL on core versions without the API.
   */
  private function valueManager(): ?PluginManagerInterface {
    if (!$this->container->has('plugin.manager.design_token_value')) {
      return NULL;
    }
    $manager = $this->container->get('plugin.manager.design_token_value');
    \assert($manager instanceof PluginManagerInterface);
    return $manager;
  }

  /**
   * Whether core has the design token API and a `color` value type.
   */
  public function isAvailable(): bool {
    return $this->entityTypeManager->hasDefinition('design_token')
      && ($this->valueManager()?->hasDefinition('color') ?? FALSE);
  }

  /**
   * Whether the site opted in, and the API is available.
   */
  public function isEnabled(): bool {
    return $this->isAvailable() && (bool) $this->configFactory->get('canvas.settings')->get(self::SETTING);
  }

  /**
   * Turns the feature on or off. Deletes nothing.
   */
  public function setEnabled(bool $enabled): void {
    $this->configFactory->getEditable('canvas.settings')->set(self::SETTING, $enabled)->save();
  }

  /**
   * Resets the per-request caches. Called by the `design_token` hooks.
   */
  public function resetIndex(): void {
    $this->listed = NULL;
    $this->byPath = NULL;
  }

  /**
   * The entity ID for a token path. Depends on the path alone.
   *
   * The path is lowercased, `.` becomes `__` and anything else outside
   * `[a-z0-9_]` becomes `_`. When that loses information, a hash of the path
   * is appended.
   */
  public static function entityId(string $path): string {
    $id = (string) \preg_replace('/[^a-z0-9_]/', '_', \strtr(\strtolower($path), ['.' => '__']));
    // `core.design_token.` + ID must fit ConfigEntityStorage::MAX_ID_LENGTH.
    $max = 166 - \strlen('core.design_token.');
    if (\preg_match('/[^a-z0-9.]/', $path) || \str_contains($path, '__') || \strlen($id) > $max) {
      $id = \substr($id, 0, $max - 9) . '_' . \substr(\hash('sha256', $path), 0, 8);
    }
    return $id;
  }

  /**
   * The ID of the staged config update holding a token's draft.
   *
   * Fixed length, so the auto-save key always fits `key_value.name`.
   */
  public static function stagedUpdateId(string $path): string {
    return self::STAGED_ID_PREFIX . \substr(\hash('sha256', $path), 0, 16);
  }

  /**
   * The CSS variable for a path, by core's rule.
   *
   * @see \Drupal\Core\Theme\Entity\DesignToken::getCssVariableName()
   */
  public static function cssVariable(string $path): string {
    return '--' . \strtr($path, ['_' => '-', ' ' => '-', '.' => '-']);
  }

  private static function lastSegment(string $path): string {
    $segments = \explode('.', $path);
    return $segments[\count($segments) - 1];
  }

  public static function isStorablePath(string $path): bool {
    return (bool) \preg_match(self::STORABLE_PATH, $path);
  }

  /**
   * Display name for a path segment: short or numbered ones stay verbatim.
   */
  public static function displayName(string $segment): string {
    if (\strlen($segment) <= 3 || \preg_match('/\d/', $segment)) {
      return $segment;
    }
    $words = \strtolower((string) \preg_replace(['/([a-z])([A-Z])/', '/[-_\s]+/'], ['$1 $2', ' '], $segment));
    return \ucfirst(\trim($words));
  }

  /**
   * The listed definitions, keyed by path, in display order.
   *
   * @return array<string, \Drupal\Core\Theme\DesignToken\DesignToken>
   */
  private function listedDefinitions(): array {
    if ($this->listed !== NULL) {
      return $this->listed;
    }
    $this->listed = [];
    if (!$this->isAvailable()) {
      return $this->listed;
    }
    $providers = $this->providerOrder();
    $by_provider = \array_fill_keys(\array_keys($providers), []);
    foreach ($this->allDefinitions() as $definition) {
      if (isset($by_provider[$definition->getProvider()]) && \in_array($definition->getType(), self::TYPES, TRUE)) {
        $by_provider[$definition->getProvider()][$definition->getPath()] = $definition;
      }
    }
    foreach ($by_provider as $definitions) {
      $this->listed += $definitions;
    }
    return $this->listed;
  }

  /**
   * @return array<string, \Drupal\Core\Theme\DesignToken\DesignToken>
   */
  private function allDefinitions(): array {
    $manager = $this->container->get('plugin.manager.design_token');
    \assert($manager instanceof PluginManagerInterface);
    return \array_filter($manager->getDefinitions(), static fn ($d): bool => $d instanceof DesignTokenDefinition);
  }

  /**
   * Listed providers: the default theme, its base themes, then modules.
   *
   * @return array<string, array{type: 'theme'|'module', name: string, label: string}>
   */
  private function providerOrder(): array {
    $providers = [];
    $default = (string) $this->configFactory->get('system.theme')->get('default');
    $themes = $this->themeHandler->listInfo();
    // The default theme, then its base themes, nearest first.
    for ($theme = $default; \is_string($theme) && isset($themes[$theme]); $theme = $themes[$theme]->info['base theme'] ?? NULL) {
      $providers[$theme] = ['type' => 'theme', 'name' => $theme, 'label' => $this->themeHandler->getName($theme)];
    }
    $modules = \array_map('strval', \array_keys($this->moduleHandler->getModuleList()));
    \sort($modules);
    foreach ($modules as $module) {
      $providers[$module] ??= [
        'type' => 'module',
        'name' => $module,
        'label' => $this->moduleExtensionList->getName($module),
      ];
    }
    return $providers;
  }

  /**
   * Every `design_token` entity, keyed by path.
   *
   * Entities are emitted in ID order, so when two share a path the last one
   * by ID wins in CSS, and here.
   *
   * @return array<string, \Drupal\Core\Theme\Entity\DesignToken>
   */
  private function entitiesByPath(): array {
    if ($this->byPath === NULL) {
      $this->byPath = [];
      foreach ($this->loadEntities() as $entity) {
        $this->byPath[$entity->get('path')] = $entity;
      }
    }
    return $this->byPath;
  }

  /**
   * @return array<string, \Drupal\Core\Theme\Entity\DesignToken>
   */
  private function loadEntities(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $entities = $this->entityTypeManager->getStorage('design_token')->loadMultiple();
    \ksort($entities);
    /** @var array<string, \Drupal\Core\Theme\Entity\DesignToken> $entities */
    return $entities;
  }

  public function findByPath(string $path): ?DesignToken {
    return $this->entitiesByPath()[$path] ?? NULL;
  }

  private static function isManaged(DesignToken $entity): bool {
    return (bool) $entity->getThirdPartySetting('canvas', 'managed', FALSE);
  }

  /**
   * @return array<string, mixed>
   */
  private static function scopes(DesignToken $entity): array {
    return $entity->get('scopes') ?? [];
  }

  /**
   * The published `:root` value of a token, in stored form, or NULL.
   */
  public function publishedValue(string $path): mixed {
    $entity = $this->findByPath($path);
    if ($entity === NULL) {
      return NULL;
    }
    $definition = $this->listedDefinitions()[$path] ?? NULL;
    if ($definition !== NULL && $definition->getType() !== $entity->get('type')) {
      return NULL;
    }
    $value = self::scopes($entity)[self::ROOT] ?? NULL;
    return $value === NULL ? NULL : self::normalize($entity->get('type'), $value);
  }

  /**
   * Whether an unlisted token may be reset: only Canvas's own override.
   *
   * Overrides left behind after a theme switch, or after the theme stopped
   * declaring the path, can only be reset. No other value can be written.
   */
  public function isResettableUnlisted(string $path, string $type): bool {
    $entity = $this->findByPath($path);
    return $this->listedType($path) === NULL
      && $entity !== NULL
      && self::isManaged($entity)
      && $entity->get('type') === $type
      && \array_key_exists(self::ROOT, self::scopes($entity));
  }

  /**
   * The type of a listed token, or NULL when the path is not listed.
   */
  public function listedType(string $path): ?string {
    return ($this->listedDefinitions()[$path] ?? NULL)?->getType();
  }

  /**
   * Theme tokens for the brand kit client representation.
   *
   * Listed definitions first, in display order, then "Other values": entities
   * whose path matches no listed definition.
   *
   * @return list<array<string, mixed>>
   *   `BrandKitThemeToken` items; see openapi.yml.
   */
  public function themeTokens(): array {
    if (!$this->isAvailable()) {
      return [];
    }
    $enabled = $this->isEnabled();
    $providers = $this->providerOrder();
    $entities = $this->entitiesByPath();
    $drafts = $this->stagedValues();
    $tokens = [];

    foreach ($this->listedDefinitions() as $path => $definition) {
      $type = (string) $definition->getType();
      $entity = $entities[$path] ?? NULL;
      $declared = self::normalize($type, $definition->value->toDtcg());
      $published = $enabled ? $this->publishedValue($path) : NULL;
      $segments = \explode('.', $path);
      \array_pop($segments);
      $tokens[] = self::token($path, $type, $published ?? $declared, $declared, $published !== NULL, $drafts[$path] ?? NULL) + [
        'name' => self::displayName($definition->name),
        'description' => $definition->description ? (string) $definition->description : NULL,
        'provider' => $providers[$definition->getProvider()],
        'groupPath' => $segments,
        'editable' => self::isStorablePath($path),
        'resettable' => $published !== NULL && $entity !== NULL && (self::isManaged($entity) || \count(self::scopes($entity)) > 1),
        'reason' => self::isStorablePath($path) ? NULL : (string) $this->t("The theme's name for this value can't be saved yet."),
      ];
    }

    $all = $this->allDefinitions();
    $all_paths = \array_map(static fn (DesignTokenDefinition $d): string => $d->getPath(), $all);
    foreach ($entities as $path => $entity) {
      if (isset($this->listedDefinitions()[$path])) {
        continue;
      }
      $type = (string) $entity->get('type');
      $root = self::scopes($entity)[self::ROOT] ?? NULL;
      $value = $root === NULL ? NULL : self::normalize($type, $root);
      $reason = match (TRUE) {
        \in_array($path, $all_paths, TRUE) => $this->t('From a theme that is not the default'),
        !self::isManaged($entity) => $this->t('Set by another module'),
        default => $this->t('No longer defined by the theme'),
      };
      // Overrides of "Other values" take effect only while the feature is on.
      $tokens[] = self::token($path, $type, $value, NULL, $enabled && $value !== NULL, $drafts[$path] ?? NULL) + [
        'name' => self::displayName(self::lastSegment($path)),
        'description' => NULL,
        'provider' => NULL,
        'groupPath' => [],
        'editable' => FALSE,
        'resettable' => $enabled && $this->isResettableUnlisted($path, $type),
        'reason' => (string) $reason,
      ];
    }
    return $tokens;
  }

  /**
   * @param array{value: mixed}|null $draft
   *
   * @return array<string, mixed>
   */
  private static function token(string $path, string $type, mixed $value, mixed $declared, bool $overridden, ?array $draft): array {
    $token = [
      'path' => $path,
      'reference' => '{' . $path . '}',
      'cssVariable' => self::cssVariable($path),
      'type' => $type,
      'value' => $value,
      'declaredValue' => $declared,
      'overridden' => $overridden,
      'draft' => $draft === NULL ? NULL : ['value' => $draft['value']],
    ];
    if ($type === 'color') {
      $token['displayFormat'] = NULL;
    }
    return $token;
  }

  /**
   * The groups that contain listed tokens, ancestors included, in order.
   *
   * @return list<array{provider: string, path: list<string>, name: string, description: string|null}>
   */
  public function themeTokenGroups(): array {
    $groups = [];
    foreach ($this->listedDefinitions() as $definition) {
      $chain = [];
      for ($group = $definition->parent; $group instanceof Group; $group = $group->parent) {
        \array_unshift($chain, $group);
      }
      foreach ($chain as $group) {
        $key = $definition->getProvider() . ':' . $group->getPath();
        $groups[$key] ??= [
          'provider' => $definition->getProvider(),
          'path' => \explode('.', $group->getPath()),
          'name' => self::displayName($group->name),
          'description' => $group->description ? (string) $group->description : NULL,
        ];
      }
    }
    return \array_values($groups);
  }

  /**
   * Counts for the enable confirmation, or NULL unless off and available.
   *
   * @return array{otherModuleValues: int, collidingSiteColors: int}|null
   */
  public function enableImpact(): ?array {
    if (!$this->isAvailable() || $this->isEnabled()) {
      return NULL;
    }
    $other = \array_filter($this->loadEntities(), static fn (DesignToken $e): bool => !self::isManaged($e));
    return [
      'otherModuleValues' => \count($other),
      'collidingSiteColors' => \count($this->collidingSiteColors()),
    ];
  }

  /**
   * Site colors whose CSS variable equals a listed token's variable.
   *
   * @return array<string, string>
   *   Token paths, keyed by site color UUID.
   */
  public function collidingSiteColors(): array {
    $variables = [];
    foreach (\array_keys($this->listedDefinitions()) as $path) {
      $variables[self::cssVariable($path)] = $path;
    }
    $colliding = [];
    foreach ($this->entityTypeManager->getStorage(Color::ENTITY_TYPE_ID)->loadMultiple() as $color) {
      \assert($color instanceof Color);
      if (isset($variables[$color->getCssVariable()])) {
        $colliding[(string) $color->id()] = $variables[$color->getCssVariable()];
      }
    }
    return $colliding;
  }

  /**
   * Adds the cacheability every theme token listing or resolution depends on.
   */
  public static function addCacheability(RefinableCacheableDependencyInterface $cacheability): void {
    $cacheability->addCacheTags([
      self::MODE_CACHE_TAG,
      'design_token',
      'config:design_token_list',
      'config:core.extension',
      'config:system.theme',
      'config:canvas.settings',
    ]);
  }

  /**
   * Pending drafts, keyed by path. Empty while the feature is off.
   *
   * @return array<string, array{value: mixed, base: mixed}>
   */
  public function stagedValues(): array {
    if (!$this->isEnabled()) {
      return [];
    }
    $prefix = StagedConfigUpdate::ENTITY_TYPE_ID . ':' . self::STAGED_ID_PREFIX;
    $values = [];
    // ponytail: scans the auto-save list; add a prefix query if it grows large.
    foreach ($this->autoSaveManager->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE) as $key => $entry) {
      if (!\str_starts_with((string) $key, $prefix)) {
        continue;
      }
      foreach ($entry['data']['actions'] ?? [] as $action) {
        if (($action['name'] ?? NULL) === self::CONFIG_ACTION && isset($action['input']['path'])) {
          $values[$action['input']['path']] = [
            'value' => $action['input']['value'] ?? NULL,
            'base' => $action['input']['base'] ?? NULL,
          ];
        }
      }
    }
    return $values;
  }

  /**
   * Validates a value for a type, and returns it normalized (stored form).
   *
   * Canvas checks what core does not: font family names (which core emits
   * unquoted), finite numbers, dimension units, weight range, and complete
   * typography values. Numbers are rounded to 4 decimal places.
   *
   * @return array{0: mixed, 1: array<string, string>}
   *   The normalized value, and error messages keyed by sub-path ('' for the
   *   value itself).
   */
  public static function validateValue(string $type, mixed $value): array {
    $errors = [];
    $normalized = self::check($type, $value, '', $errors);
    return [$normalized, $errors];
  }

  /**
   * @param array<string, string> $errors
   */
  private static function check(string $type, mixed $value, string $at, array &$errors): mixed {
    $number = static function (mixed $n, string $at) use (&$errors): ?float {
      if ((!\is_int($n) && !\is_float($n)) || !\is_finite((float) $n)) {
        $errors[$at] = 'Enter a finite number.';
        return NULL;
      }
      return \round((float) $n, 4);
    };
    $sub = static fn (string $key): string => $at === '' ? $key : "$at.$key";

    switch ($type) {
      case 'color':
        $color = ColorValue::parse($value);
        if ($color === NULL) {
          $errors[$at] = 'Enter a valid color.';
        }
        return $color;

      case 'fontFamily':
        if (\is_string($value)) {
          $value = [$value];
        }
        if (!\is_array($value) || $value === [] || !\array_is_list($value)) {
          $errors[$at] = 'Add at least one font family.';
          return $value;
        }
        foreach ($value as $i => $family) {
          if (!\is_string($family) || !\preg_match(self::FONT_FAMILY, $family)) {
            $errors[$sub((string) $i)] = "Family names with digits at the start of a word or with punctuation aren't supported yet.";
          }
        }
        return $value;

      case 'fontWeight':
        if (\is_float($value) && \floor($value) === $value) {
          $value = (int) $value;
        }
        if (!\is_int($value) || $value < 1 || $value > 1000) {
          $errors[$at] = 'Enter a whole number from 1 to 1000.';
        }
        return $value;

      case 'number':
        return $number($value, $at);

      case 'dimension':
        if (!\is_array($value)) {
          $errors[$at] = 'Enter a number and a unit.';
          return $value;
        }
        $n = $number($value['value'] ?? NULL, $sub('value'));
        if (!\in_array($value['unit'] ?? NULL, ['px', 'rem'], TRUE)) {
          $errors[$sub('unit')] = 'The unit must be px or rem.';
        }
        return ['value' => $n, 'unit' => $value['unit'] ?? NULL];

      case 'typography':
        $properties = [
          'fontFamily' => 'fontFamily',
          'fontSize' => 'dimension',
          'fontWeight' => 'fontWeight',
          'letterSpacing' => 'dimension',
          'lineHeight' => 'number',
        ];
        if (!\is_array($value)) {
          $errors[$at] = 'Enter all five typography properties.';
          return $value;
        }
        $result = [];
        foreach ($properties as $property => $property_type) {
          if (!\array_key_exists($property, $value)) {
            $errors[$sub($property)] = 'This property is required.';
            continue;
          }
          $result[$property] = self::check($property_type, $value[$property], $sub($property), $errors);
        }
        return $result;

      default:
        $errors[$at] = 'This kind of value cannot be edited.';
        return $value;
    }
  }

  /**
   * Whether two values are equal once normalized: `1` equals `1.0`.
   */
  public static function sameValue(string $type, mixed $a, mixed $b): bool {
    if ($a === NULL || $b === NULL) {
      return $a === $b;
    }
    return self::normalize($type, $a) == self::normalize($type, $b);
  }

  /**
   * The label of a token's draft: "Style guide: Provider / Group / Name".
   */
  public function draftLabel(string $path): string {
    $definition = $this->listedDefinitions()[$path] ?? NULL;
    $parts = [];
    if ($definition !== NULL) {
      $parts[] = $this->providerOrder()[$definition->getProvider()]['label'] ?? $definition->getProvider();
      for ($group = $definition->parent; $group instanceof Group; $group = $group->parent) {
        \array_splice($parts, 1, 0, [self::displayName($group->name)]);
      }
    }
    $parts[] = self::tokenName($path);
    return 'Style guide: ' . \implode(' / ', $parts);
  }

  /**
   * Normalizes a stored or declared value for the client.
   */
  private static function normalize(string $type, mixed $value): mixed {
    [$normalized, $errors] = self::validateValue($type, $value);
    // Values other tools wrote may be invalid: pass them through as stored.
    return $errors === [] ? $normalized : $value;
  }

  /**
   * Validates a `:root` value for a token, including core's config schema.
   *
   * @return array{0: mixed, 1: array<string, string>}
   *   The normalized value and errors, as ::validateValue() returns them.
   */
  public function validateRootValue(string $path, string $type, mixed $value): array {
    [$normalized, $errors] = self::validateValue($type, $value);
    if ($errors !== [] || !$this->isAvailable()) {
      return [$normalized, $errors];
    }
    $existing = $this->findByPath($path);
    $scopes = ($existing ? self::scopes($existing) : []);
    $scopes[self::ROOT] = $normalized;
    $unsaved = $this->entityTypeManager->getStorage('design_token')->create([
      'id' => $existing?->id() ?? self::entityId($path),
      'path' => $path,
      'type' => $type,
      'scopes' => $scopes,
    ]);
    foreach ($unsaved->getTypedData()->validate() as $violation) {
      $property = (string) \preg_replace('/^scopes\.:root\.?/', '', $violation->getPropertyPath());
      $errors[$property] = (string) $violation->getMessage();
    }
    return [$normalized, $errors];
  }

  /**
   * Sets the `:root` value of a token, creating a managed entity if needed.
   *
   * Other scopes are preserved. Callers must have checked access.
   *
   * @throws \InvalidArgumentException
   *   When the value is invalid, or the path has no known type.
   */
  public function setRootValue(string $path, mixed $value, ?string $type = NULL): void {
    $entity = $this->findByPath($path);
    $type = $this->listedType($path) ?? $entity?->get('type') ?? $type;
    if ($type === NULL || !self::isStorablePath($path)) {
      throw new \InvalidArgumentException(\sprintf('The design token "%s" cannot be set.', $path));
    }
    if ($entity !== NULL && $entity->get('type') !== $type) {
      // `type` is immutable. Only an entity Canvas owns outright may be
      // replaced; its old value is dropped.
      if (!self::isManaged($entity) || \array_keys(self::scopes($entity)) !== [self::ROOT]) {
        throw new \InvalidArgumentException(\sprintf('The design token "%s" has another type.', $path));
      }
      $entity->delete();
      $entity = NULL;
    }
    [$normalized, $errors] = $this->validateRootValue($path, $type, $value);
    if ($errors !== []) {
      throw new \InvalidArgumentException(\sprintf('Invalid value for "%s": %s', $path, \implode(' ', $errors)));
    }
    if ($entity === NULL) {
      $entity = $this->entityTypeManager->getStorage('design_token')->create([
        'id' => self::entityId($path),
        'path' => $path,
        'type' => $type,
        'scopes' => [],
      ]);
      \assert($entity instanceof DesignToken);
      $entity->setThirdPartySetting('canvas', 'managed', TRUE);
    }
    $scopes = self::scopes($entity);
    $scopes[self::ROOT] = $normalized;
    $entity->set('scopes', $scopes)->save();
    $this->resetIndex();
  }

  /**
   * Removes the `:root` value of a token.
   *
   * A Canvas-managed entity with no other scope is deleted. Any other entity
   * keeps its other scopes, and is never deleted.
   */
  public function resetRootValue(string $path): void {
    $entity = $this->findByPath($path);
    if ($entity === NULL || !\array_key_exists(self::ROOT, self::scopes($entity))) {
      return;
    }
    $scopes = self::scopes($entity);
    unset($scopes[self::ROOT]);
    if ($scopes === [] && self::isManaged($entity)) {
      $entity->delete();
      $this->resetIndex();
      return;
    }
    // Core's LazyPluginCollection::removeInstanceId() keeps the removed
    // scope's configuration, which preSave() writes back. Saving while
    // syncing skips both the collection and that write-back.
    // @todo Remove once core fixes LazyPluginCollection::removeInstanceId().
    $entity->setSyncing(TRUE);
    try {
      $entity->set('scopes', $scopes)->save();
    }
    finally {
      $entity->setSyncing(FALSE);
      $this->entityTypeManager->getStorage('design_token')->resetCache([(string) $entity->id()]);
      $this->resetIndex();
    }
  }

  /**
   * CSS for every `design_token` entity, while the feature is on.
   *
   * Each entity is serialized by core, on its own: one that cannot be (a
   * missing type plugin, an invalid value another tool saved) is skipped and
   * logged.
   *
   * @param bool $with_drafts
   *   Whether to apply staged values, for the draft brand kit stylesheet.
   */
  public function css(bool $with_drafts = FALSE): string {
    if (!$this->isEnabled()) {
      return '';
    }
    $entities = $this->loadEntities();
    if ($with_drafts) {
      $storage = $this->entityTypeManager->getStorage('design_token');
      foreach ($this->stagedValues() as $path => $draft) {
        $existing = $this->findByPath($path);
        $type = $this->listedType($path) ?? $existing?->get('type');
        if ($type === NULL) {
          continue;
        }
        $scopes = $existing && $existing->get('type') === $type ? self::scopes($existing) : [];
        unset($scopes[self::ROOT]);
        if ($draft['value'] !== NULL) {
          $scopes[self::ROOT] = $draft['value'];
        }
        $id = $existing?->id() ?? self::entityId($path);
        // An unsaved copy: never mutate the loaded entity.
        $entities[$id] = $storage->create(['id' => $id, 'path' => $path, 'type' => $type, 'scopes' => $scopes]);
      }
      \ksort($entities);
    }
    $css = '';
    foreach ($entities as $entity) {
      if (!$entity instanceof DesignToken) {
        continue;
      }
      try {
        $css .= DesignToken::toCss([$entity]);
      }
      catch (\Throwable $e) {
        Error::logException($this->logger, $e, 'Skipped design token %id in the brand kit stylesheet: %message', ['%id' => $entity->id()]);
      }
    }
    return $css;
  }

  /**
   * Resolves a `{path}` color reference to its effective DTCG color value.
   *
   * While the feature is off, this is the declared value.
   *
   * @return array{colorSpace: string, components: list<float>, alpha: float|null, hex: string|null}|null
   */
  public function resolveColor(string $path, RefinableCacheableDependencyInterface $cacheability): ?array {
    $cacheability->addCacheTags([self::MODE_CACHE_TAG, 'design_token', 'config:core.extension', 'config:system.theme']);
    if (!$this->isAvailable()) {
      return NULL;
    }
    $entity = $this->findByPath($path);
    if ($entity !== NULL) {
      $cacheability->addCacheableDependency($entity);
    }
    else {
      $cacheability->addCacheTags(['config:design_token_list']);
    }
    $value = $this->isEnabled() ? $this->publishedValue($path) : NULL;
    if ($value === NULL && $this->listedType($path) === 'color') {
      $value = self::normalize('color', $this->listedDefinitions()[$path]->value->toDtcg());
    }
    return ColorValue::parse($value);
  }

  /**
   * The display name of a listed or stored token, for resolved color props.
   */
  public static function tokenName(string $path): string {
    return self::displayName(self::lastSegment($path));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onConfigSave'];
  }

  /**
   * Turning the feature on or off changes CSS order and resolved values.
   *
   * Covers every path that changes the setting: the routes, drush and config
   * import.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== 'canvas.settings' || !$event->isChanged(self::SETTING)) {
      return;
    }
    $this->resetIndex();
    // `library_info` also clears the CSS asset cache, which hook_css_alter()
    // results live in.
    Cache::invalidateTags([self::MODE_CACHE_TAG, 'library_info']);
    BrandKit::regenerateAssets();
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    $manager = $this->valueManager();
    if ($module !== 'canvas' || !$this->entityTypeManager->hasDefinition('design_token') || $manager === NULL) {
      return [];
    }
    $definition = $manager->getDefinition('color', FALSE);
    if (($definition['class'] ?? NULL) !== ColorValue::class) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('design_token')->getQuery()->accessCheck(FALSE)->condition('type', 'color')->execute();
    if ($ids === []) {
      return [];
    }
    return [(string) $this->t('Design tokens of type color depend on Canvas: @ids. Delete them first.', ['@ids' => \implode(', ', $ids)])];
  }

}
