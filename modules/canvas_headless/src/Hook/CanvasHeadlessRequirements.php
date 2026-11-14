<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Hook;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\PreviewAssertionFactory;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Runtime requirements for Drupal Canvas Headless.
 *
 * The OOP hook rather than the procedural hook_requirements(): the module
 * requires Drupal 11.3, where a procedural implementation is deprecated —
 * and where marking it #[LegacyRequirementsHook] would stop it running
 * altogether rather than silence the deprecation.
 */
class CanvasHeadlessRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FrontendCacheTagInvalidator $frontendCacheTagInvalidator,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Surfaces a missing preview consumer: without it, every assertion
   * exchange fails with invalid_client and the preview never starts. This is
   * a safety net for sites whose consumer was deleted, or that were
   * installed before the consumer was provisioned on config-sync installs.
   *
   * Also warns about the frontends that an invalidated cache tag may not have
   * reached: they serve stale pages until they read an announcement again.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];
    $storage = $this->entityTypeManager->getStorage('consumer');
    if (!$storage->loadByProperties(['client_id' => PreviewAssertionFactory::CLIENT_ID])) {
      $requirements['canvas_headless_consumer'] = [
        'title' => $this->t('Drupal Canvas Headless preview consumer'),
        'description' => $this->t('The OAuth consumer the frontend app redeems preview assertions with is missing, so headless previews cannot start. Reinstall the module to provision it.'),
        'severity' => RequirementSeverity::Error,
      ];
    }

    $failing = [];
    foreach ($this->frontendCacheTagInvalidator->getStatus() as $url => $status) {
      if ($status['state'] === 'failing') {
        $failing[] = $this->t('@url: @error (@time)', [
          '@url' => $url,
          '@error' => $status['lastError'] ?? $this->t('An announcement did not reach the frontend'),
          '@time' => $status['lastFailure'] === NULL ? $this->t('unknown time') : $this->dateFormatter->format($status['lastFailure'], 'short'),
        ]);
      }
    }
    if ($failing !== []) {
      $requirements['canvas_headless_cache_invalidation'] = [
        'title' => $this->t('Drupal Canvas Headless cache invalidation'),
        'value' => $this->t('Failing'),
        'description' => [
          ['#markup' => $this->t('Invalidated cache tags may not have reached these frontends, which may serve stale pages:')],
          ['#theme' => 'item_list', '#items' => $failing],
          ['#markup' => $this->t('Each frontend is told to expire everything once it is reachable.')],
        ],
        'severity' => RequirementSeverity::Warning,
      ];
    }
    return $requirements;
  }

}
