<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use Drupal\canvas\Messenger;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[CoversClass(Messenger::class)]
#[Group('canvas')]
final class MessengerTest extends UnitTestCase {

  /**
   * Messages survive only on the routes that return a preview.
   *
   * `canvas.api.layout.content_template_draft` is not one of them: it returns
   * JSON without a preview, so nothing would ever display, let alone delete,
   * the message.
   */
  #[TestWith(['entity.node.canonical', TRUE])]
  #[TestWith(['canvas.api.config.list', FALSE])]
  #[TestWith(['canvas.api.layout.get', TRUE])]
  #[TestWith(['canvas.api.layout.get.content_template', TRUE])]
  #[TestWith(['canvas.api.layout.patch.pattern', TRUE])]
  #[TestWith(['canvas.api.layout.post', TRUE])]
  #[TestWith(['canvas.api.layout.content_template_draft', FALSE])]
  #[TestWith([NULL, TRUE])]
  public function testAddMessage(?string $route_name, bool $expected_to_be_added): void {
    $decorated = $this->prophesize(MessengerInterface::class);
    $decorated->addMessage('Test message', MessengerInterface::TYPE_STATUS, FALSE)
      ->shouldBeCalledTimes((int) $expected_to_be_added);
    $route_match = $this->prophesize(RouteMatchInterface::class);
    $route_match->getRouteName()->willReturn($route_name);

    $messenger = new Messenger($decorated->reveal(), $route_match->reveal());
    self::assertSame($messenger, $messenger->addStatus('Test message'));
  }

}
