<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\StyleLibraryAccessControlHandler;
use Drupal\canvas\Style\ComponentStyles;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A Canvas-managed style library, in core's `*.utilities.yml` format.
 *
 * For sites without a custom theme or module (for example Acquia Source), so
 * design owners can declare styles from the CLI. Libraries are published on
 * save: core's renderer reads the global style plugin manager, so a draft
 * could not render in a preview without leaking to the live site.
 *
 * Pushed config is untrusted, unlike theme YAML, so options may only set
 * `class`, `style`, `data-*`, or `aria-*`, and `library` is not allowed.
 *
 * @see \Drupal\canvas\Hook\StyleHooks::styleAlter()
 * @see https://www.drupal.org/project/drupal/issues/3517033
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Style library'),
  label_singular: new TranslatableMarkup('style library'),
  label_plural: new TranslatableMarkup('style libraries'),
  label_collection: new TranslatableMarkup('Style libraries'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => StyleLibraryAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  config_export: [
    'id',
    'label',
    'styles',
  ],
  constraints: [
    'ImmutableProperties' => [
      'properties' => [
        'id',
      ],
    ],
  ],
)]
final class StyleLibrary extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface {

  public const string ENTITY_TYPE_ID = 'style_library';
  public const string CONFIG_PREFIX = 'canvas.style_library.';
  public const string ADMIN_PERMISSION = 'administer style libraries';

  /**
   * Attributes an option may set.
   */
  public const string ALLOWED_ATTRIBUTE_PATTERN = '/^(class|style|data-[a-z0-9-]+|aria-[a-z-]+)$/';

  protected string $id;
  protected ?string $label;

  /**
   * Styles keyed by style ID, in core's utilities format.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $styles = [];

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getStyles(): array {
    return $this->styles;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `StyleLibrary` in openapi.yml.
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id,
        'label' => $this->label,
        'styles' => (object) $this->styles,
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function createFromClientSide(array $data): static {
    return static::create(self::cleanClientData($data));
  }

  /**
   * {@inheritdoc}
   */
  public function updateFromClientSide(array $data): void {
    $data = self::cleanClientData($data);
    unset($data['id']);
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }
  }

  /**
   * Normalizes client data: empty JSON objects decode as empty arrays.
   */
  private static function cleanClientData(array $data): array {
    if (\array_key_exists('styles', $data) && !\is_array($data['styles'])) {
      $data['styles'] = [];
    }
    return \array_intersect_key($data, \array_flip(['id', 'label', 'styles']));
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    // Keep exports deterministic.
    ksort($this->styles);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    // Published on save: definitions are rebuilt, and pages that used them
    // are invalidated through the definitions cache tag.
    \Drupal::service(ComponentStyles::class)->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service(ComponentStyles::class)->clearCachedDefinitions();
  }

  /**
   * Every library's styles as raw style definitions, keyed by style ID.
   *
   * Reads raw config, not entities: core calls this while building its
   * definitions (through hook_style_alter()), which must not depend on the
   * entity system.
   *
   * @return array<string, array<string, mixed>>
   *
   * @see \Drupal\canvas\Hook\StyleHooks::styleAlter()
   */
  public static function rawDefinitions(ConfigFactoryInterface $config_factory): array {
    $definitions = [];
    foreach ($config_factory->listAll(self::CONFIG_PREFIX) as $config_name) {
      $library = $config_factory->get($config_name);
      foreach ($library->get('styles') ?? [] as $style_id => $style) {
        if (!\is_array($style) || !preg_match(ComponentStyles::STYLE_ID_PATTERN, (string) $style_id)) {
          continue;
        }
        // Defensive: config validation already rejects these.
        unset($style['library'], $style['target']);
        $definitions[(string) $style_id] ??= [
          ...$style,
          'id' => (string) $style_id,
          'provider' => ComponentStyles::LIBRARY_PROVIDER,
          'type' => 'utility',
          // Marks the definition as Canvas-managed, for cache tags, data
          // health, and the editor's provider badge.
          'canvas_library' => (string) $library->get('id'),
        ];
      }
    }
    return $definitions;
  }

  /**
   * Validates style and option IDs, and rejects IDs an extension provides.
   *
   * Config schema validates the shape; this validates what it cannot: keys.
   *
   * Validates `type: canvas.style_library.*` config.
   */
  public static function validateStyles(mixed $styles, ExecutionContextInterface $context): void {
    if (!\is_array($styles)) {
      return;
    }
    $root = $context->getRoot();
    $library_id = $root?->get('id')?->getValue();
    $extension_styles = [];
    foreach (\Drupal::service(ComponentStyles::class)->sourceDefinitions() as $style_id => $definition) {
      $is_this_library = $definition['canvas_library'] === $library_id;
      $is_other_library = $definition['canvas_library'] !== NULL;
      if (!$is_this_library && !$is_other_library) {
        $extension_styles[$style_id] = $definition['provider'];
      }
      elseif ($is_other_library && !$is_this_library) {
        $extension_styles[$style_id] = 'style library ' . $definition['canvas_library'];
      }
    }
    foreach ($styles as $style_id => $style) {
      $style_id = (string) $style_id;
      if (!preg_match(ComponentStyles::STYLE_ID_PATTERN, $style_id)) {
        $context->buildViolation('"%id" is not a valid style ID. Style IDs contain only lowercase letters, numbers, and underscores.', ['%id' => $style_id])
          ->atPath($style_id)
          ->addViolation();
        continue;
      }
      if (isset($extension_styles[$style_id])) {
        $context->buildViolation('The style "%id" is already provided by %provider.', [
          '%id' => $style_id,
          '%provider' => $extension_styles[$style_id],
        ])
          ->atPath($style_id)
          ->addViolation();
      }
      foreach (\array_keys(\is_array($style['options'] ?? NULL) ? $style['options'] : []) as $option_id) {
        if (!preg_match(ComponentStyles::OPTION_ID_PATTERN, (string) $option_id)) {
          $context->buildViolation('"%option" is not a valid option ID for style "%id".', [
            '%option' => $option_id,
            '%id' => $style_id,
          ])
            ->atPath("$style_id.options.$option_id")
            ->addViolation();
        }
      }
    }
  }

}
