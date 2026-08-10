<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo\Hook;

use Drupal\canvas_personalization\Entity\SegmentInterface;
use Drupal\canvas_personalization_vwo\Plugin\SegmentCondition\VwoAudience;
use Drupal\canvas_personalization_vwo\VwoFmeAudienceResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use wingify\Wingify;

/**
 * Surfaces a misconfigured VWO integration on the status report.
 *
 * Failing closed is correct but silent: a site whose credentials are missing
 * serves the default variant to every visitor and looks entirely healthy. The
 * segment condition contract has no health or diagnostics seam, so the
 * integration reports its own — and only once an enabled segment actually
 * depends on VWO, so installing the module changes nothing on its own.
 */
class VwoRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    if (!$this->hasEnabledVwoRule()) {
      return [];
    }
    $problems = [];
    if (!\class_exists(Wingify::class)) {
      $problems[] = $this->t('The VWO FME PHP SDK is not installed. Run <code>composer require wingify/wingify-fme-php-sdk</code>.');
    }
    $credentials = Settings::get(VwoFmeAudienceResolver::SETTINGS_KEY, []);
    if (!\is_array($credentials) || ($credentials['sdk_key'] ?? '') === '' || ($credentials['account_id'] ?? NULL) === NULL) {
      $problems[] = $this->t('VWO credentials are missing. Set the <code>@key</code> key in settings.php to an array with <code>sdk_key</code> and <code>account_id</code>.', ['@key' => VwoFmeAudienceResolver::SETTINGS_KEY]);
    }
    if ($problems === []) {
      return [];
    }
    return [
      'canvas_personalization_vwo' => [
        'title' => $this->t('Drupal Canvas Personalization: VWO'),
        'value' => $this->t('Every visitor falls back to the default variant'),
        'description' => [
          '#theme' => 'item_list',
          '#items' => $problems,
        ],
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

  /**
   * Whether any enabled segment actually depends on VWO.
   */
  private function hasEnabledVwoRule(): bool {
    $segments = $this->entityTypeManager->getStorage('segment')->loadByProperties(['status' => TRUE]);
    foreach ($segments as $segment) {
      \assert($segment instanceof SegmentInterface);
      if (\array_key_exists(VwoAudience::PLUGIN_ID, $segment->getSegmentRules())) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
