<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Entity\Oauth2TokenInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests preview-token scope inspection.
 */
#[CoversClass(PreviewTokenInspector::class)]
#[Group('canvas_headless')]
final class PreviewTokenInspectorTest extends UnitTestCase {

  /**
   * Tests malformed token scope fields are not treated as preview tokens.
   */
  public function testRejectsUnexpectedScopeFieldType(): void {
    $token = $this->createMock(Oauth2TokenInterface::class);
    $token->method('get')
      ->with('scopes')
      ->willReturn($this->createMock(FieldItemListInterface::class));
    $account = $this->createMock(TokenAuthUserInterface::class);
    $account->method('getToken')->willReturn($token);

    self::assertFalse(PreviewTokenInspector::hasPreviewScope($account));
  }

}
