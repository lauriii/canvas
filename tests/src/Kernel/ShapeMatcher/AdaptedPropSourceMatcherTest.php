<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\Plugin\Adapter\DayCountAdapter;
use Drupal\canvas\Plugin\Adapter\ImageAdapter;
use Drupal\canvas\Plugin\Adapter\ImageAndStyleAdapter;
use Drupal\canvas\Plugin\Adapter\ImageUriAdapter;
use Drupal\canvas\Plugin\Adapter\UnixTimestampToDateAdapter;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\ShapeMatcher\AdaptedPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[CoversClass(AdaptedPropSourceMatcher::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class AdaptedPropSourceMatcherTest extends PropSourceMatcherTestBase {

  /**
   * {@inheritdoc}
   */
  protected string $testedPropSourceMatcherClass = AdaptedPropSourceMatcher::class;

  /**
   * {@inheritdoc}
   */
  protected array $expectedMatches = [
    'type=integer' => DayCountAdapter::PLUGIN_ID,
    'type=object&$ref=json-schema-definitions://canvas.module/image' => [
      ImageAndStyleAdapter::PLUGIN_ID,
      ImageAdapter::PLUGIN_ID,
    ],
    'type=string&$ref=json-schema-definitions://canvas.module/image-uri' => ImageUriAdapter::PLUGIN_ID,
    'type=string&format=date' => UnixTimestampToDateAdapter::PLUGIN_ID,
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition(AdaptedPropSourceMatcher::class)->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function performMatch(bool $is_required, PropShape $prop_shape): array {
    $matcher = \Drupal::service(AdaptedPropSourceMatcher::class);
    \assert($matcher instanceof AdaptedPropSourceMatcher);
    return \array_map(
      fn (AdapterInterface $adapter): string => $adapter->getPluginId(),
      $matcher->match($is_required, $prop_shape),
    );
  }

}
