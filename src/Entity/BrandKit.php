<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\BrandKitAccessControlHandler;
use Drupal\canvas\EntityHandlers\CanvasAssetStorage;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @phpstan-type FontAxis array{
 *   tag: string,
 *   name?: string,
 *   min: float,
 *   max: float,
 *   default: float
 * }
 * @phpstan-type FontAxisInput array{
 *   tag: string,
 *   name?: string|null,
 *   min: float|int|string,
 *   max: float|int|string,
 *   default: float|int|string
 * }
 * @phpstan-type FontEntry array{
 *   id: string,
 *   family: string,
 *   uri: string,
 *   format: string,
 *   weight: string,
 *   style: string,
 *   axes?: list<FontAxis>|null
 * }
 * @phpstan-type FontEntryInput array{
 *   id: string,
 *   family: string,
 *   uri: string,
 *   format: string,
 *   weight: string,
 *   style: string,
 *   axes?: list<FontAxisInput>|null
 * }
 * @phpstan-type FontValidationAxis array{
 *   tag?: string,
 *   min?: float|int|string,
 *   max?: float|int|string,
 *   default?: float|int|string
 * }
 * @phpstan-type FontValidationEntry array{
 *   uri?: string,
 *   axes?: list<FontValidationAxis>|null
 * }
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Brand kit'),
  label_singular: new TranslatableMarkup('brand kit'),
  label_plural: new TranslatableMarkup('brand kits'),
  label_collection: new TranslatableMarkup('Brand kits'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'storage' => CanvasAssetStorage::class,
    'access' => BrandKitAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  links: [],
  config_export: [
    'id',
    'label',
    'fonts',
  ],
)]
final class BrandKit extends ConfigEntityBase implements CanvasAssetInterface {

  use CanvasAssetLibraryTrait {
    getCss as private getCompiledCss;
  }

  public const string ENTITY_TYPE_ID = 'brand_kit';
  public const string ADMIN_PERMISSION = 'administer brand kit';
  public const string FILE_USAGE_TYPE = 'brand_kit';
  public const string AUTO_SAVE_FILE_USAGE_TYPE = 'brand_kit_auto_save';
  private const string ASSETS_DIRECTORY = AssetLibrary::ASSETS_DIRECTORY;
  public const string ARTIFACTS_DIRECTORY = 'public://canvas/assets/';

  public const string GLOBAL_ID = 'global';

  protected string $id;

  /**
   * The human-readable label of the brand kit.
   */
  protected ?string $label;

  /**
   * @var list<FontEntry>|null
   */
  protected ?array $fonts = NULL;

  /**
   * {@inheritdoc}
   *
   * This corresponds to `BrandKit` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function normalizeForClientSide(): ClientSideRepresentation {
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);

    return ClientSideRepresentation::create(
      values: [
        'id' => $this->id,
        'label' => $this->label,
        'fonts' => $this->fonts === NULL
          ? NULL
          : (static function (array $fonts) use ($file_url_generator): array {
            $normalized_fonts = [];
            foreach ($fonts as $font_entry) {
              $normalized_fonts[] = [
                ...self::normalizeFontEntry($font_entry),
                'variantType' => !empty($font_entry['axes']) ? 'variable' : 'static',
                'url' => $file_url_generator->generateString((string) $font_entry['uri']),
              ];
            }
            return $normalized_fonts;
          })($this->fonts),
      ],
      preview: NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCss(): string {
    $generated_css = $this->buildGeneratedCss();
    $compiled_css = $this->getCompiledCss();

    if ($generated_css === '') {
      return $compiled_css;
    }

    if (trim($compiled_css) === '') {
      return $generated_css;
    }

    return $generated_css . "\n\n" . $compiled_css;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `BrandKit` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public static function createFromClientSide(array $data): static {
    $entity = static::create(['id' => $data['id']]);
    $entity->updateFromClientSide($data);
    return $entity;
  }

  /**
   * {@inheritdoc}
   *
   * This corresponds to `BrandKit` in openapi.yml.
   *
   * @see docs/adr/0005-Keep-the-front-end-simple.md
   */
  public function updateFromClientSide(array $data): void {
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function refineListQuery(
    QueryInterface &$query,
    RefinableCacheableDependencyInterface $cacheability,
  ): void {
    // Nothing to do.
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    $original = $update ? $this->getOriginal() : NULL;
    self::syncFontFileUsage(
      $original instanceof self ? self::getFontUris($original->getFonts()) : [],
      self::getFontUris($this->getFonts()),
      self::FILE_USAGE_TYPE,
      (string) $this->id(),
    );
    Cache::invalidateTags(['library_info']);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    foreach ($entities as $entity) {
      if ($entity instanceof self) {
        self::clearFontFileUsage(
          self::getFontUris($entity->getFonts()),
          self::FILE_USAGE_TYPE,
          (string) $entity->id(),
        );
      }
    }
  }

  /**
   * @return list<FontEntry>
   */
  public function getFonts(): array {
    if ($this->fonts === NULL) {
      return [];
    }

    $fonts = [];
    foreach ($this->fonts as $entry) {
      $fonts[] = self::normalizeFontEntry($entry);
    }

    return $fonts;
  }

  /**
   * @param array|null $entries
   *   The uploaded font entries.
   *
   * @phpstan-param list<FontEntryInput>|null $entries
   */
  public function setFonts(?array $entries): void {
    if ($entries === NULL) {
      $this->fonts = NULL;
      return;
    }

    $fonts = [];
    foreach ($entries as $entry) {
      $fonts[] = self::normalizeFontEntry($entry);
    }

    $this->fonts = $fonts;
  }

  /**
   * Updates file usage records for a set of font URIs.
   *
   * @param list<string> $old_uris
   * @param list<string> $new_uris
   */
  public static function syncFontFileUsage(array $old_uris, array $new_uris, string $type, string $id): void {
    $file_usage = \Drupal::service('file.usage');
    \assert($file_usage instanceof FileUsageInterface);

    foreach (\array_values(\array_diff($new_uris, $old_uris)) as $uri) {
      $file = self::loadFileByUri($uri);
      if ($file instanceof FileInterface) {
        $file_usage->add($file, 'canvas', $type, $id);
      }
    }

    foreach (\array_values(\array_diff($old_uris, $new_uris)) as $uri) {
      $file = self::loadFileByUri($uri);
      if ($file instanceof FileInterface) {
        $file_usage->delete($file, 'canvas', $type, $id, 0);
      }
    }
  }

  /**
   * Clears file usage records for font URIs.
   *
   * @param list<string> $uris
   */
  public static function clearFontFileUsage(array $uris, string $type, string $id): void {
    $file_usage = \Drupal::service('file.usage');
    \assert($file_usage instanceof FileUsageInterface);

    foreach ($uris as $uri) {
      $file = self::loadFileByUri($uri);
      if ($file instanceof FileInterface) {
        $file_usage->delete($file, 'canvas', $type, $id, 0);
      }
    }
  }

  /**
   * Updates auto-save file usage records for Brand kit font URIs.
   *
   * @todo Replace BrandKit-specific auto-save file tracking with an interface so
   *   any config entity can opt in generically.
   */
  public static function syncAutoSaveFileUsage(
    CanvasHttpApiEligibleConfigEntityInterface $old,
    CanvasHttpApiEligibleConfigEntityInterface $new,
    string $id,
  ): void {
    if (!$old instanceof self || !$new instanceof self) {
      return;
    }

    self::syncFontFileUsage(
      self::getFontUris($old->getFonts()),
      self::getFontUris($new->getFonts()),
      self::AUTO_SAVE_FILE_USAGE_TYPE,
      $id,
    );
  }

  /**
   * Clears auto-save file usage records for Brand kit font URIs.
   */
  public static function clearAutoSaveFileUsage(
    CanvasHttpApiEligibleConfigEntityInterface $entity,
    string $id,
  ): void {
    if (!$entity instanceof self) {
      return;
    }

    self::clearFontFileUsage(
      self::getFontUris($entity->getFonts()),
      self::AUTO_SAVE_FILE_USAGE_TYPE,
      $id,
    );
  }

  /**
   * @param array $entry
   *   The uploaded font entry.
   *
   * @return array
   *   The normalized font entry.
   *
   * @phpstan-param FontEntryInput $entry
   * @phpstan-return FontEntry
   */
  private static function normalizeFontEntry(array $entry): array {
    $axes = NULL;
    if (\array_key_exists('axes', $entry) && \is_array($entry['axes'])) {
      $axes = [];
      foreach ($entry['axes'] as $axis) {
        $axes[] = \array_filter([
          'tag' => (string) $axis['tag'],
          'name' => isset($axis['name'])
            ? (string) $axis['name']
            : NULL,
          'min' => (float) $axis['min'],
          'max' => (float) $axis['max'],
          'default' => (float) $axis['default'],
        ], static fn(mixed $value): bool => $value !== NULL);
      }
    }

    return [
      'id' => (string) $entry['id'],
      'family' => (string) $entry['family'],
      'uri' => (string) $entry['uri'],
      'format' => (string) $entry['format'],
      'weight' => (string) $entry['weight'],
      'style' => (string) $entry['style'],
      'axes' => $axes,
    ];
  }

  /**
   * @param list<FontEntry> $fonts
   *
   * @return list<string>
   */
  private static function getFontUris(array $fonts): array {
    return \array_values(\array_unique(\array_map(
      static fn (array $font): string => (string) $font['uri'],
      $fonts,
    )));
  }

  private static function loadFileByUri(string $uri): ?FileInterface {
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    return $file instanceof FileInterface ? $file : NULL;
  }

  private function buildGeneratedCss(): string {
    $fonts = $this->getFonts();
    if ($fonts === []) {
      return '';
    }

    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);

    $rules = [];
    foreach ($fonts as $font) {
      $rules[] = self::buildFontFaceRule($font, $file_url_generator);
    }

    return implode("\n\n", $rules);
  }

  /**
   * @param array $font
   *   The font entry.
   *
   * @phpstan-param FontEntry $font
   */
  private static function buildFontFaceRule(
    array $font,
    FileUrlGeneratorInterface $file_url_generator,
  ): string {
    $lines = [
      '@font-face {',
      \sprintf("  font-family: '%s';", self::escapeCssString($font['family'])),
      \sprintf(
        "  src: url('%s') format('%s');",
        self::escapeCssString($file_url_generator->generateString($font['uri'])),
        self::getFontFormatLabel($font['format']),
      ),
      \sprintf('  font-weight: %s;', $font['weight']),
      \sprintf('  font-style: %s;', $font['style']),
      '}',
    ];

    return implode("\n", $lines);
  }

  /**
   * Escapes a string for safe use inside single-quoted CSS values.
   */
  private static function escapeCssString(string $value): string {
    return \str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
  }

  private static function getFontFormatLabel(string $format): string {
    return match ($format) {
      'woff2' => 'woff2',
      'woff' => 'woff',
      'ttf' => 'truetype',
      'otf' => 'opentype',
      default => $format,
    };
  }

  /**
   * Validates persisted variable font metadata.
   *
   * @param array|null $fonts
   *   The uploaded font entries.
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation execution context.
   *
   * @phpstan-param list<FontValidationEntry>|null $fonts
   */
  public static function validateFonts(?array $fonts, ExecutionContextInterface $context): void {
    if ($fonts === NULL) {
      return;
    }

    foreach ($fonts as $font_index => $font) {
      $uri = $font['uri'] ?? NULL;
      if (\is_string($uri) && $uri !== '') {
        $file = self::loadFileByUri($uri);
        if (!$file instanceof FileInterface) {
          $context
            ->buildViolation('The URI must reference an existing managed file.')
            ->atPath("[$font_index][uri]")
            ->addViolation();
        }
      }

      $axes = \is_array($font['axes'] ?? NULL) ? $font['axes'] : [];
      foreach ($axes as $axis_index => $axis) {
        $tag = (string) ($axis['tag'] ?? '');
        if (\strlen($tag) !== 4) {
          $context
            ->buildViolation('Axis tags must be exactly 4 characters long.')
            ->atPath("[$font_index][axes][$axis_index][tag]")
            ->addViolation();
        }

        $min = (float) ($axis['min'] ?? 0);
        $max = (float) ($axis['max'] ?? 0);
        $default = (float) ($axis['default'] ?? 0);

        if ($min > $max || $default < $min || $default > $max) {
          $context
            ->buildViolation('Axis defaults must stay within the declared min/max range.')
            ->atPath("[$font_index][axes][$axis_index][default]")
            ->addViolation();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetLibrary(bool $isPreview): string {
    return 'canvas/brand_kit.' . $this->id() . ($isPreview ? '.draft' : '');
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetLibraryDependencies(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $properties = parent::toArray();
    // Omit NULL fonts property to satisfy NotBlank constraint.
    // If there are no entries, the key should be omitted entirely.
    if ($properties['fonts'] === NULL) {
      unset($properties['fonts']);
    }
    return $properties;
  }

}
