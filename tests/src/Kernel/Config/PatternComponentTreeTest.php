<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\experience_builder\Entity\Pattern;

/**
 * Tests the component tree aspects of the Pattern config entity type.
 *
 * @group experience_builder
 * @coversDefaultClass \Drupal\experience_builder\Entity\Pattern
 */
final class PatternComponentTreeTest extends ConfigWithComponentTreeTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entity = Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test pattern',
    ]);
  }

}
