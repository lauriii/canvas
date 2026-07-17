<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ShapeMatcher;

use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\ShapeMatcher\AdaptedPropSourceMatcher;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves per-shape adapter matching against representative SDC prop shapes.
 *
 * Every unique (storable) prop shape in the sdc_test_all_props component is
 * matched, so this doubles as the coverage proof that each Phase 1 adapter's
 * declared output schema matches the shapes it is meant for.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(AdaptedPropSourceMatcher::class)]
#[Group('canvas')]
#[Group('canvas_shape_matching')]
class AdaptedPropSourceMatcherTest extends PropSourceMatcherTestBase {

  /**
   * The parametric adapters: their output mirrors designated inputs.
   *
   * They match every target prop shape, so they are expected for every
   * unique prop shape below.
   *
   * @see \Drupal\canvas\Plugin\Adapter\Adapter::$outputMirrorsInputs
   */
  private const PARAMETRIC_ADAPTERS = ['contains', 'equals', 'fallback', 'mapping'];

  /**
   * {@inheritdoc}
   */
  protected string $testedPropSourceMatcherClass = AdaptedPropSourceMatcher::class;

  /**
   * Merges shape-specific adapter IDs with the parametric ones, sorted by ID.
   *
   * @return list<string>
   */
  private static function withParametric(string ...$shape_specific): array {
    $expected = [...$shape_specific, ...self::PARAMETRIC_ADAPTERS];
    sort($expected);
    return $expected;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->expectedMatches = [
      'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object&maxItems=2' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[$ref]=json-schema-definitions://canvas.module/image&items[type]=object&minItems=1' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&items[enum][0]=10&items[enum][1]=20&items[enum][2]=30&items[enum][3]=40&items[meta:enum][10]=Ten&items[meta:enum][20]=Twenty&items[meta:enum][30]=Thirty&items[meta:enum][40]=Forty' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&items[enum][0]=10&items[enum][1]=20&items[enum][2]=30&items[enum][3]=40&items[meta:enum][10]=Ten&items[meta:enum][20]=Twenty&items[meta:enum][30]=Thirty&items[meta:enum][40]=Forty&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&items[minimum]=-100&items[maximum]=100&maxItems=100&minItems=1' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&maxItems=2' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&maxItems=20&minItems=1' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=integer&minItems=1' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=number' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=number&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[enum][0]=option_one&items[enum][1]=option_two&items[enum][2]=option_three&items[enum][3]=option_four&items[meta:enum][option_one]=Option One&items[meta:enum][option_two]=Option Two&items[meta:enum][option_three]=Option Three&items[meta:enum][option_four]=Option Four' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[enum][0]=option_one&items[enum][1]=option_two&items[enum][2]=option_three&items[enum][3]=option_four&items[meta:enum][option_one]=Option One&items[meta:enum][option_two]=Option Two&items[meta:enum][option_three]=Option Three&items[meta:enum][option_four]=Option Four&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[enum][0]=red&items[enum][1]=blue&items[enum][2]=green_light&items[enum][3]=yellow&items[meta:enum][red]=Red&items[meta:enum][blue]=Blue&items[meta:enum][green.light]=Light Green&items[meta:enum][yellow]=Yellow' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[enum][0]=red&items[enum][1]=green&items[enum][2]=blue&items[enum][3]=yellow&items[meta:enum][red]=Red&items[meta:enum][green]=Green&items[meta:enum][blue]=Blue&items[meta:enum][yellow]=Yellow' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=date' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=date&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=date-time' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=date-time&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=uri' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=uri&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=uri-reference' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&items[format]=uri-reference&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&maxItems=3' => self::PARAMETRIC_ADAPTERS,
      'type=array&items[type]=string&minItems=1' => self::PARAMETRIC_ADAPTERS,
      'type=boolean' => self::withParametric('is_set'),
      'type=integer' => self::withParametric('day_count'),
      'type=integer&$ref=json-schema-definitions://canvas.module/column-width' => self::PARAMETRIC_ADAPTERS,
      'type=integer&enum[0]=1&enum[1]=2' => self::PARAMETRIC_ADAPTERS,
      'type=integer&enum[0]=1&enum[1]=2&enum[2]=3&enum[3]=4&enum[4]=5&enum[5]=6' => self::PARAMETRIC_ADAPTERS,
      'type=integer&maximum=2147483648&minimum=-2147483648' => self::PARAMETRIC_ADAPTERS,
      'type=integer&minimum=0' => self::PARAMETRIC_ADAPTERS,
      'type=integer&minimum=1' => self::PARAMETRIC_ADAPTERS,
      'type=number' => self::PARAMETRIC_ADAPTERS,
      'type=object&$ref=json-schema-definitions://canvas.module/image' => self::withParametric('image_apply_style', 'image_url_rel_to_abs'),
      'type=object&$ref=json-schema-definitions://canvas.module/video' => self::PARAMETRIC_ADAPTERS,
      'type=string' => self::withParametric('combine', 'format_date', 'prefix_suffix'),
      'type=string&$ref=json-schema-definitions://canvas.module/heading-element' => self::PARAMETRIC_ADAPTERS,
      'type=string&$ref=json-schema-definitions://canvas.module/image-uri' => self::withParametric('image_extract_url'),
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-image-uri' => self::PARAMETRIC_ADAPTERS,
      'type=string&$ref=json-schema-definitions://canvas.module/stream-wrapper-uri' => self::PARAMETRIC_ADAPTERS,
      'type=string&contentMediaType=text/html' => self::PARAMETRIC_ADAPTERS,
      'type=string&contentMediaType=text/html&x-formatting-context=block' => self::PARAMETRIC_ADAPTERS,
      'type=string&contentMediaType=text/html&x-formatting-context=inline' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=7&enum[1]=3.14' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=_self&enum[1]=_blank' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=auto&enum[1]=manual' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=foo&enum[1]=bar' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=full&enum[1]=wide&enum[2]=normal&enum[3]=narrow' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=horizontal&enum[1]=vertical' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=lazy&enum[1]=eager' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=php&enum[1]=html&enum[2]=md&enum[3]=js&enum[4]=ts&enum[5]=jsx&enum[6]=tsx' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=primary&enum[1]=secondary' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=primary&enum[1]=success&enum[2]=neutral&enum[3]=warning&enum[4]=danger' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=small&enum[1]=big&enum[2]=huge' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=small&enum[1]=big&enum[2]=huge&enum[3]=contains.dots' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=small&enum[1]=medium&enum[2]=large' => self::PARAMETRIC_ADAPTERS,
      'type=string&enum[0]=top&enum[1]=bottom&enum[2]=start&enum[3]=end' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=date' => self::withParametric('unix_to_date'),
      'type=string&format=date-time' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=email' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=idn-email' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=iri' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=iri-reference' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=uri' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=uri-reference' => self::PARAMETRIC_ADAPTERS,
      'type=string&format=uri-reference&x-allowed-schemes[0]=http&x-allowed-schemes[1]=https' => self::PARAMETRIC_ADAPTERS,
      'type=string&pattern=(.|\\r?\\n)*' => self::PARAMETRIC_ADAPTERS,
    ];
  }

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
