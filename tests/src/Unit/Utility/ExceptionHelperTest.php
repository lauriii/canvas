<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Utility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\canvas\Utility\ExceptionHelper;
use Drupal\Tests\canvas\Doubles\TestVerboseException;
use Drupal\Tests\UnitTestCase;

/**
 * Tests Drupal\canvas\Utility\ExceptionHelper.
 */
#[CoversClass(ExceptionHelper::class)]
#[Group('canvas')]
class ExceptionHelperTest extends UnitTestCase {

  /**
   * Tests get verbose message.
   *
   * @legacy-covers ::getVerboseMessage
   */
  #[DataProvider('exceptionProvider')]
  public function testGetVerboseMessage(\Throwable $exception, string $expected_message): void {
    $result = ExceptionHelper::getVerboseMessage($exception);
    $this->assertEquals($expected_message, $result);
  }

  public static function exceptionProvider(): array {
    return [
      [
        'exception' => new \Exception('Basic message'),
        'expected_message' => 'Basic message',
      ],
      [
        'exception' => new TestVerboseException('Basic message', 'Verbose message'),
        'expected_message' => 'Verbose message',
      ],
    ];
  }

}
