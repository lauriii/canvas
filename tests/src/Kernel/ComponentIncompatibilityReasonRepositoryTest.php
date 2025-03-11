<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ComponentIncompatibilityReasonRepository.
 *
 * @covers \Drupal\experience_builder\ComponentIncompatibilityReasonRepository
 * @group JavaScriptComponents
 * @group experience_builder
 */
final class ComponentIncompatibilityReasonRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * Covers ComponentIncompatibilityReasonRepository.
   */
  public function testRepository(): void {
    $repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($repository instanceof ComponentIncompatibilityReasonRepository);
    $repository->storeReasons('sketches', 'house', ['Missing door']);
    $repository->storeReasons('sketches', 'dog', ['Missing tail']);
    $repository->storeReasons('petra', 'dragon', ['Climate apocalypse', 'Large and scaly']);
    self::assertEquals([
      'sketches' => [
        'house' => ['Missing door'],
        'dog' => ['Missing tail'],
      ],
      'petra' => [
        'dragon' => [
          'Climate apocalypse',
          'Large and scaly',
        ],
      ],
    ], $repository->getReasons());
    $repository->removeReason('sketches', 'house');
    self::assertEquals([
      'sketches' => [
        'dog' => ['Missing tail'],
      ],
      'petra' => [
        'dragon' => [
          'Climate apocalypse',
          'Large and scaly',
        ],
      ],
    ], $repository->getReasons());
    $repository->updateReasons('petra', ['converge' => ['Gray snakes slither across country']]);
    self::assertEquals([
      'sketches' => [
        'dog' => ['Missing tail'],
      ],
      'petra' => [
        'converge' => ['Gray snakes slither across country'],
      ],
    ], $repository->getReasons());
  }

}
