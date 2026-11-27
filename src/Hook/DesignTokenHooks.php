<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\DesignToken\BrandKitTokens;
use Drupal\canvas\DesignToken\ColorValue;
use Drupal\canvas\Entity\BrandKit;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hooks integrating the brand kit with core's design tokens API.
 *
 * @see \Drupal\canvas\DesignToken\BrandKitTokens
 * @internal
 */
final class DesignTokenHooks {

  use StringTranslationTrait;

  private bool $regenerationPending = FALSE;

  public function __construct(
    private readonly BrandKitTokens $tokens,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_design_token_value_info_alter().
   *
   * Registers the `color` polyfill, only when core has no color type.
   */
  #[Hook('design_token_value_info_alter')]
  public static function designTokenValueInfoAlter(array &$definitions): void {
    $definitions['color'] ??= [
      'id' => 'color',
      'label' => new TranslatableMarkup('Color'),
      'class' => ColorValue::class,
      // Makes `color` entities depend on Canvas.
      'provider' => 'canvas',
    ];
  }

  #[Hook('design_token_insert')]
  #[Hook('design_token_update')]
  #[Hook('design_token_delete')]
  public function designTokenChanged(): void {
    $this->tokens->resetIndex();
    // Publishing many drafts saves many entities in one transaction:
    // regenerate once, after it ends (which also runs after a rollback, to
    // rebuild the old state).
    if ($this->database->inTransaction()) {
      if (!$this->regenerationPending) {
        $this->regenerationPending = TRUE;
        $this->database->transactionManager()->addPostTransactionCallback(function (): void {
          $this->regenerationPending = FALSE;
          BrandKit::regenerateAssets();
        });
      }
      return;
    }
    // ponytail: config import regenerates per entity; collapse into one
    // ConfigEvents::IMPORT regeneration if imports with many tokens are slow.
    BrandKit::regenerateAssets();
  }

  /**
   * Implements hook_css_alter().
   *
   * While the feature is on, the brand kit stylesheet loads after theme CSS,
   * so values set in Canvas win over the theme's own `:root` defaults. Sites
   * that never opt in keep today's order.
   */
  #[Hook('css_alter')]
  public function cssAlter(array &$css): void {
    if (!$this->tokens->isEnabled()) {
      return;
    }
    $brand_kit = BrandKit::load(BrandKit::GLOBAL_ID);
    $production = $brand_kit?->hasCss() ? $brand_kit->getCssPath() : NULL;
    $draft = \sprintf('/canvas/api/v0/auto-saves/css/%s/%s', BrandKit::ENTITY_TYPE_ID, BrandKit::GLOBAL_ID);

    $max_weight = 0;
    foreach ($css as $item) {
      if (($item['group'] ?? NULL) === \CSS_AGGREGATE_THEME) {
        $max_weight = \max($max_weight, $item['weight'] ?? 0);
      }
    }
    foreach ($css as $key => &$item) {
      if ($key === $production || $key === $draft) {
        $item['group'] = \CSS_AGGREGATE_THEME;
        $item['weight'] = $max_weight + 1;
      }
    }
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    if ($this->tokens->isAvailable() || !$this->configFactory->get('canvas.settings')->get(BrandKitTokens::SETTING)) {
      return [];
    }
    return [
      'canvas_design_tokens' => [
        'title' => $this->t('Canvas theme design values'),
        'value' => $this->t('Unavailable'),
        'description' => $this->t('The Canvas style guide is turned on, but this version of Drupal core has no compatible design tokens API. It behaves as if it were turned off.'),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
