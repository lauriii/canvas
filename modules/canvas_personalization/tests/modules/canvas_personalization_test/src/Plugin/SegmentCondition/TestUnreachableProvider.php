<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_test\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Simulates a third-party segmentation provider that is unreachable.
 *
 * Declares the cacheability a real provider condition would (a cookie-derived
 * membership lookup with a bounded TTL), then throws on evaluation — allowing
 * tests to prove the fail-closed contract: the page renders the default
 * variant, and the declared cacheability still reaches the response.
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Test unreachable provider'),
)]
final class TestUnreachableProvider extends SegmentConditionBase {

  public const string PLUGIN_ID = 'test_unreachable_provider';

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    throw new \RuntimeException('The external segmentation provider is unreachable.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['cookies:canvas_test_provider'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 300;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('Membership in an external provider segment');
  }

}
