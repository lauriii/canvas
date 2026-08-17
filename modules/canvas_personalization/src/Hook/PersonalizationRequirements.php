<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Hook;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\Entity\SegmentInterface;
use Drupal\canvas_personalization\Plugin\SegmentCondition\Geolocation;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Status report entries for the personalization module.
 */
final class PersonalizationRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Drupal cannot tell a geolocation header its own edge set from one the
   * visitor sent: if the edge does not overwrite both headers on every inbound
   * request, a visitor selects a variant by sending them. Only the edge can
   * close that, so the site's operator has to know about it — but only once a
   * segment actually selects variants on those headers, which is when the
   * headers start deciding what visitors see — see docs/personalization.md
   * §3.3 for the deployment requirement itself.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    foreach ($this->entityTypeManager->getStorage(Segment::ENTITY_TYPE_ID)->loadMultiple() as $segment) {
      \assert($segment instanceof SegmentInterface);
      if (!$segment->status() || !\array_key_exists(Geolocation::PLUGIN_ID, $segment->getSegmentRules())) {
        continue;
      }
      $settings = $this->configFactory->get('canvas_personalization.settings');
      return [
        'canvas_personalization_geolocation_headers' => [
          'title' => $this->t('Canvas Personalization geolocation headers'),
          'value' => $this->t('Trusted: @headers', [
            '@headers' => \implode(', ', [$settings->get('country_header'), $settings->get('region_header')]),
          ]),
          'severity' => RequirementSeverity::Warning,
          'description' => $this->t('Enabled segments select personalization variants from these request headers, so Drupal trusts whatever value arrives in them. Your CDN or reverse proxy MUST overwrite both headers on every request it forwards; where it does not, a visitor can pick another variant by sending them.'),
        ],
      ];
    }
    return [];
  }

}
