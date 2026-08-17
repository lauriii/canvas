<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\SegmentCondition;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for segment condition plugins.
 *
 * Segment conditions are the building blocks of personalization segments: each
 * condition evaluates the current request context to a boolean. Cacheability is
 * part of the contract: a condition MUST declare the cache contexts its result
 * varies by, and a max-age bounding how long its result stays valid, so that
 * personalized responses remain cacheable — and cache-safe — for anonymous
 * users.
 *
 * Segment conditions MUST NOT set cache tags: cache tags express dependencies
 * on stored data, whereas a condition's result may only depend on request
 * context (cache contexts) and time (max-age). Tags returned by a condition
 * are discarded and logged.
 *
 * A condition's declared cache contexts MUST be derivable from its
 * configuration alone — never from the current request's values. The
 * page-cache integration reads them on requests where a cache served the
 * personalized response without evaluating anything; contexts that appear
 * only under certain requests would silently poison the URL-keyed page
 * cache. (The max-age may be time-dependent, e.g. "seconds until midnight",
 * but must not depend on request context either.)
 *
 * Third-party segmentation providers integrate by implementing this interface
 * (typically by extending SegmentConditionBase) plus a config schema. A
 * condition resolving membership from an external service must fail closed:
 * when the service is unreachable, return FALSE — the visitor falls back to
 * the default variant. Evaluation exceptions are caught, logged, and treated
 * as FALSE by the segment evaluator.
 *
 * @see \Drupal\canvas_personalization\Attribute\SegmentCondition
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase
 * @see docs/personalization.md
 */
interface SegmentConditionInterface extends ConfigurableInterface, CacheableDependencyInterface, PluginInspectionInterface, PluginFormInterface {

  /**
   * Evaluates the condition against the current request context.
   *
   * Implementations honor the `negate` setting; see SegmentConditionBase.
   */
  public function evaluate(): bool;

  /**
   * Returns a human-readable summary of the configured condition.
   */
  public function summary(): \Stringable|string;

}
