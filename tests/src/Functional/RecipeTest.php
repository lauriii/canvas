<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;

/**
 * Tests that a recipe installing Canvas and Navigation together can be applied.
 *
 * @see https://git.drupalcode.org/project/canvas/-/issues/3570043
 */
class RecipeTest extends GenericRecipeTestBase {

  protected function getRecipePath(): string {
    $path = (new \ReflectionObject($this))->getFileName();
    self::assertIsString($path);
    return dirname($path, 4) . '/tests/fixtures/recipes/3570043';
  }

}
