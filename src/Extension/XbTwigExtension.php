<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Extension;

use Twig\Extension\AbstractExtension;

/**
 * Defines a twig extension to add metadata to output as HTML comments.
 */
final class XbTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors() {
    return [
      new XbPropVisitor(),
    ];
  }

}
