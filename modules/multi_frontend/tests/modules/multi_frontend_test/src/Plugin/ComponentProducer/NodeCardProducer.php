<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Plugin\ComponentProducer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\multi_frontend\Attribute\ComponentProducer;
use Drupal\multi_frontend\ComponentProducerBase;
use Drupal\multi_frontend\ProducerContext;
use Drupal\node\NodeInterface;

/**
 * Produces a card for a node.
 */
#[ComponentProducer(
  id: 'multi_frontend_test.card',
  component: 'multi_frontend_test:card',
  subject: 'entity:node',
  label: new TranslatableMarkup('Node card'),
)]
final class NodeCardProducer extends ComponentProducerBase {

  /**
   * {@inheritdoc}
   */
  public function produce(mixed $subject, ProducerContext $context): array {
    \assert($subject instanceof NodeInterface);
    // Counts invocations, so a test can show that a render cache hit does not
    // reach the producer at all.
    $state = \Drupal::state();
    $state->set('multi_frontend_test.produce_count', $state->get('multi_frontend_test.produce_count', 0) + 1);
    $context->addCacheableDependency($subject);

    return [
      'title' => $subject->label(),
      'url' => $subject->toUrl()->toString(TRUE)->getGeneratedUrl(),
      'createdAt' => (new \DateTimeImmutable('@' . (int) $subject->getCreatedTime()))
        ->setTimezone(new \DateTimeZone('UTC'))
        ->format(\DateTimeInterface::ATOM),
      // Read through the context, never off the entity: this applies the
      // field's own view access and runs the text format's filters, both of
      // which the field formatter would have done and a producer replaces.
      'summary' => $context->formattedText($subject, 'body'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function produceSlots(mixed $subject, ProducerContext $context): array {
    \assert($subject instanceof NodeInterface);
    // A slot holds nodes, not markup, which is what lets a converted component
    // compose with another one, or with an unconverted subtree.
    return [
      'footer' => [$context->produceChild('multi_frontend_test.byline', $subject->getOwner())],
    ];
  }

}
