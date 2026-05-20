<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Marks prop sources rendered as "linked" inputs in the UI.
 *
 * In the UI, a prop populated by one of these sources is considered linked to
 * data outside the component instance.
 *
 * @see \Drupal\canvas\Element\LinkedProp
 *
 * @internal
 */
interface LinkablePropSourceInterface {

  public function asChoice(): string;

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array;

  /**
   * Returns the human-readable label for this prop source when linked.
   *
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $host_entity_data_definition
   *   The host entity type+bundle data definition. Used by implementors whose
   *   label is contextualized by the host (e.g. EntityFieldPropSource,
   *   HostEntityPropSource); ignored by implementors whose label is
   *   host-independent (e.g. HostEntityUrlPropSource).
   */
  public function label(EntityDataDefinitionInterface $host_entity_data_definition): TranslatableMarkup;

}
