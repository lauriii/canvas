<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\File\CanvasContentFileUrlGenerator;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests content API file URL generation.
 */
#[CoversClass(CanvasContentFileUrlGenerator::class)]
#[Group('canvas_headless')]
final class CanvasContentFileUrlGeneratorTest extends UnitTestCase {

  /**
   * Tests that only content API requests receive absolute file URLs.
   */
  public function testRequestScopedAbsoluteUrls(): void {
    $inner = $this->createMock(FileUrlGeneratorInterface::class);
    $inner->expects($this->once())
      ->method('generateString')
      ->with('public://image.png')
      ->willReturn('/sites/default/files/image.png');
    $relative_url = Url::fromUri('base:/sites/default/files/image.png');
    $inner->expects($this->exactly(3))
      ->method('generateAbsoluteString')
      ->with('public://image.png')
      ->willReturn('https://drupal.example/sites/default/files/image.png');
    $inner->expects($this->once())
      ->method('generate')
      ->with('public://image.png')
      ->willReturn($relative_url);
    $inner->expects($this->once())
      ->method('transformRelative')
      ->with('https://drupal.example/sites/default/files/image.png', TRUE)
      ->willReturn('/sites/default/files/image.png');
    $request_stack = new RequestStack();
    $generator = new CanvasContentFileUrlGenerator($inner, $request_stack);

    self::assertSame('/sites/default/files/image.png', $generator->generateString('public://image.png'));
    self::assertSame($relative_url, $generator->generate('public://image.png'));
    self::assertSame(
      '/sites/default/files/image.png',
      $generator->transformRelative('https://drupal.example/sites/default/files/image.png'),
    );

    $request = Request::create('https://drupal.example/node/1');
    $request->attributes->set(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE, '/node/1');
    $request_stack->push($request);

    self::assertSame(
      'https://drupal.example/sites/default/files/image.png',
      $generator->generateString('public://image.png'),
    );
    self::assertSame(
      'https://drupal.example/sites/default/files/image.png',
      $generator->generateAbsoluteString('public://image.png'),
    );
    $absolute_url = $generator->generate('public://image.png');
    self::assertTrue($absolute_url->isExternal());
    self::assertSame(
      'https://drupal.example/sites/default/files/image.png',
      $absolute_url->getUri(),
    );
    self::assertSame(
      'https://drupal.example/sites/default/files/image.png',
      $generator->transformRelative('https://drupal.example/sites/default/files/image.png'),
    );
  }

}
