<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * For config entities that provide a stand-in host entity for field widgets.
 *
 * Component input forms build field widgets, and some field widgets need a
 * "parent" content entity. When the edited component tree belongs to a config
 * entity (a content template, a page variant), there is no real host entity,
 * so the config entity provides an empty stand-in.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
 */
interface EmptyTargetEntityProviderInterface {

  /**
   * Creates an empty content entity for field widgets to attach to.
   */
  public function createEmptyTargetEntity(): FieldableEntityInterface;

}
