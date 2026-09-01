<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Plugin\ComponentProducer;

use Drupal\user\UserInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\multi_frontend\Attribute\ComponentProducer;
use Drupal\multi_frontend\ComponentProducerBase;
use Drupal\multi_frontend\ProducerContext;

/**
 * Produces a byline for a user.
 */
#[ComponentProducer(
  id: 'multi_frontend_test.byline',
  component: 'multi_frontend_test:byline',
  subject: 'entity:user',
  label: new TranslatableMarkup('Byline'),
)]
final class BylineProducer extends ComponentProducerBase {

  /**
   * {@inheritdoc}
   */
  public function produce(mixed $subject, ProducerContext $context): array {
    \assert($subject instanceof UserInterface);
    $context->addCacheableDependency($subject);
    $name = $subject->getAccountName();
    return ['name' => $name === '' ? 'Anonymous' : $name];
  }

}
