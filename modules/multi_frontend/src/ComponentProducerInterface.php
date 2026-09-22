<?php

declare(strict_types=1);

namespace Drupal\multi_frontend;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Turns a module's internal model into the props one component declares.
 *
 * A producer returns plain arrays and scalars only. It never returns render
 * arrays, PHP objects, markup, or callbacks: whatever it returns is validated
 * against the component's props schema and serialized, and a value that
 * cannot survive that round trip is a bug rather than an escape hatch.
 */
interface ComponentProducerInterface extends PluginInspectionInterface {

  /**
   * Produces the props for one subject.
   *
   * @param mixed $subject
   *   The subject to produce props for. Its access has already been checked
   *   by whatever resolved it, which for a derived route is the route's own
   *   entity access requirement.
   * @param \Drupal\multi_frontend\ProducerContext $context
   *   The only API surface a producer is handed. Read fields through it, and
   *   record cacheability on it.
   *
   * @return array<string, mixed>
   *   Props matching the declared component's schema.
   */
  public function produce(mixed $subject, ProducerContext $context): array;

  /**
   * Produces the slots for one subject.
   *
   * Most components have no slots, so ComponentProducerBase returns an empty
   * array and implementations override only when they need to.
   *
   * @param mixed $subject
   *   The subject to produce slots for.
   * @param \Drupal\multi_frontend\ProducerContext $context
   *   The producer context.
   *
   * @return array<string, \Drupal\multi_frontend\Envelope\EnvelopeNodeInterface[]>
   *   Slot name to a list of envelope nodes.
   */
  public function produceSlots(mixed $subject, ProducerContext $context): array;

  /**
   * Returns the SDC plugin ID this producer produces props for.
   */
  public function getComponentId(): string;

}
