<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Plugin\ComponentProducer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\multi_frontend\Attribute\ComponentProducer;
use Drupal\multi_frontend\ComponentProducerBase;
use Drupal\multi_frontend\ProducerContext;
use Drupal\node\NodeInterface;

/**
 * Produces deliberately invalid props, to prove validation is unconditional.
 *
 * Also the 1:N case the producer registry is keyed for: two producers, one
 * component. Keying by component ID instead would have made this impossible
 * to express.
 */
#[ComponentProducer(
  id: 'multi_frontend_test.broken_card',
  component: 'multi_frontend_test:card',
  subject: 'entity:node',
  label: new TranslatableMarkup('Broken node card'),
)]
final class BrokenCardProducer extends ComponentProducerBase {

  /**
   * {@inheritdoc}
   */
  public function produce(mixed $subject, ProducerContext $context): array {
    \assert($subject instanceof NodeInterface);
    $context->addCacheableDependency($subject);
    return [
      'title' => $subject->label(),
      // A formatted date, which is exactly what the contract exists to stop
      // crossing the boundary. The schema says format: date-time.
      'createdAt' => 'February 2026',
    ];
  }

}
