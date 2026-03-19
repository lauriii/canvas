<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\ShapeMatcher\HostEntityUrlPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[CoversClass(HostEntityUrlPropSourceMatcher::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class HostEntityUrlPropSourceMatcherTest extends PropSourceMatcherTestBase {

  /**
   * {@inheritdoc}
   */
  protected string $testedPropSourceMatcherClass = HostEntityUrlPropSourceMatcher::class;

  private const CANONICAL_REL = [
    'sourceType' => PropSource::HostEntityUrl->value,
    'absolute' => FALSE,
  ];
  private const CANONICAL_ABS = [
    'sourceType' => PropSource::HostEntityUrl->value,
    'absolute' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  protected array $expectedMatches = [
    'type=string&format=iri' => self::CANONICAL_ABS,
    'type=string&format=iri-reference' => self::CANONICAL_REL,
    'type=string&format=uri' => self::CANONICAL_ABS,
    'type=string&format=uri-reference' => self::CANONICAL_REL,
    'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https' => self::CANONICAL_REL,
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(HostEntityUrlPropSourceMatcher::class)->setPublic(TRUE);
  }

  public function testXAllowedSchemesHttpExcluded(): void {
    $shape = PropShape::normalize([
      'type' => 'string',
      'format' => 'uri',
      'x-allowed-schemes' => ['ftp'],
    ]);
    self::assertSame([], $this->matcher->match(FALSE, $shape), 'x-allowed-schemes with only ftp should exclude http/https and return no matches.');
  }

  public function testXAllowedSchemesHttpsIncluded(): void {
    $shape = PropShape::normalize([
      'type' => 'string',
      'format' => 'uri',
      'x-allowed-schemes' => ['https'],
    ]);
    self::assertSame(
      [self::CANONICAL_ABS],
      \array_map(
        // @phpstan-ignore-next-line argument.type
        fn (HostEntityUrlPropSource $s): array => $s->toArray(),
        $this->matcher->match(FALSE, $shape),
      ),
    );
  }

  public function testContentMediaTypeExcludesMatch(): void {
    $shape = PropShape::normalize([
      'type' => 'string',
      'format' => 'uri',
      'contentMediaType' => 'image/*',
    ]);
    self::assertSame([], $this->matcher->match(FALSE, $shape), 'contentMediaType restriction should exclude HostEntityUrlPropSource (it only produces text/html URLs).');
  }

}
