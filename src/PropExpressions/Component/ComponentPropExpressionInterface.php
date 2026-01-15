<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\Component;

use Drupal\canvas\PropExpressions\PropExpressionInterface;

/**
 * @internal
 */
interface ComponentPropExpressionInterface extends PropExpressionInterface {

  /**
   * {@inheritdoc}
   *
   * Components are for graphical representations, hence a prop expression type
   * prefix that conveys that.
   */
  public const string PREFIX_EXPRESSION_TYPE = '⿲';

}
