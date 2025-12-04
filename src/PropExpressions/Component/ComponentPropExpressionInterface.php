<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\Component;

use Drupal\canvas\PropExpressions\PropExpressionInterface;

/**
 * @internal
 */
interface ComponentPropExpressionInterface extends PropExpressionInterface {

  // Components are for graphical representations.
  const PREFIX = '⿲';

}
