<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Adapter;

use Drupal\canvas\Plugin\Adapter\Adapter;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\Plugin\Adapter\CombineAdapter;
use Drupal\canvas\Plugin\Adapter\ContainsAdapter;
use Drupal\canvas\Plugin\Adapter\EqualsAdapter;
use Drupal\canvas\Plugin\Adapter\FallbackAdapter;
use Drupal\canvas\Plugin\Adapter\FormatDateAdapter;
use Drupal\canvas\Plugin\Adapter\IsSetAdapter;
use Drupal\canvas\Plugin\Adapter\MappingAdapter;
use Drupal\canvas\Plugin\Adapter\PrefixSuffixAdapter;
use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\PropSource\AdaptedPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Phase 1 adapter catalog.
 *
 * Each adapter is exercised through the real
 * `PropSource::parse()->evaluate()` path, i.e. exactly as stored component
 * trees evaluate it.
 *
 * @see https://www.drupal.org/project/canvas/issues/3464003
 */
#[CoversClass(IsSetAdapter::class)]
#[CoversClass(FormatDateAdapter::class)]
#[CoversClass(PrefixSuffixAdapter::class)]
#[CoversClass(FallbackAdapter::class)]
#[CoversClass(EqualsAdapter::class)]
#[CoversClass(ContainsAdapter::class)]
#[CoversClass(MappingAdapter::class)]
#[CoversClass(CombineAdapter::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
final class Phase1AdapterPluginsTest extends CanvasKernelTestBase {

  /**
   * Builds the array representation of a static string prop source.
   */
  private static function staticString(string $value): array {
    return [
      'sourceType' => 'static:field_item:string',
      'value' => $value,
      'expression' => 'ℹ︎string␟value',
    ];
  }

  /**
   * Builds the array representation of a static integer prop source.
   */
  private static function staticInteger(int $value): array {
    return [
      'sourceType' => 'static:field_item:integer',
      'value' => $value,
      'expression' => 'ℹ︎integer␟value',
    ];
  }

  /**
   * Builds the array representation of a static boolean prop source.
   */
  private static function staticBoolean(bool $value): array {
    return [
      'sourceType' => 'static:field_item:boolean',
      'value' => $value,
      'expression' => 'ℹ︎boolean␟value',
    ];
  }

  /**
   * @param array<string, mixed> $adapter_inputs
   */
  #[DataProvider('providerAdapt')]
  public function testAdapt(string $adapter_id, array $adapter_inputs, mixed $expected): void {
    $source = PropSource::parse([
      'sourceType' => "adapter:$adapter_id",
      'adapterInputs' => $adapter_inputs,
    ]);
    $this->assertInstanceOf(AdaptedPropSource::class, $source);
    // Prove serialization round-trips.
    $this->assertSame($source->toArray(), PropSource::parse(\json_decode((string) $source, TRUE))->toArray());
    $this->assertSame($expected, $source->evaluate(NULL, is_required: FALSE)->value);
  }

  public static function providerAdapt(): \Generator {
    // Is set / not set.
    yield 'is_set: set' => ['is_set', ['value' => self::staticString('hello')], TRUE];
    yield 'is_set: empty string' => ['is_set', ['value' => self::staticString('')], FALSE];
    yield 'is_set: negated' => ['is_set', ['value' => self::staticString(''), 'negate' => self::staticBoolean(TRUE)], TRUE];

    // Prefix / suffix.
    yield 'prefix_suffix: both' => ['prefix_suffix', ['value' => self::staticString('42'), 'prefix' => self::staticString('€'), 'suffix' => self::staticString(',-')], '€42,-'];
    yield 'prefix_suffix: integer input' => ['prefix_suffix', ['value' => self::staticInteger(7), 'prefix' => self::staticString('#')], '#7'];
    yield 'prefix_suffix: empty value adapts to nothing' => ['prefix_suffix', ['value' => self::staticString(''), 'prefix' => self::staticString('#')], NULL];

    // Fallback.
    yield 'fallback: value present' => ['fallback', ['value' => self::staticString('actual'), 'default' => self::staticString('default')], 'actual'];
    yield 'fallback: value empty' => ['fallback', ['value' => self::staticString(''), 'default' => self::staticString('default')], 'default'];

    // Equals: the "Free instead of $0.00" scenario, with loose comparison
    // because the UI enters comparison values as text.
    yield 'equals: match' => ['equals', ['value' => self::staticInteger(0), 'comparison' => self::staticString('0'), 'then' => self::staticString('Free'), 'else' => self::staticString('Paid')], 'Free'];
    yield 'equals: no match' => ['equals', ['value' => self::staticInteger(99), 'comparison' => self::staticString('0'), 'then' => self::staticString('Free'), 'else' => self::staticString('Paid')], 'Paid'];
    yield 'equals: no match, no else' => ['equals', ['value' => self::staticInteger(99), 'comparison' => self::staticString('0'), 'then' => self::staticString('Free')], NULL];
    yield 'equals: negated' => ['equals', ['value' => self::staticInteger(0), 'comparison' => self::staticString('0'), 'negate' => self::staticBoolean(TRUE), 'then' => self::staticString('Free'), 'else' => self::staticString('Paid')], 'Paid'];

    // Contains, with all three match positions and negation.
    yield 'contains: default position' => ['contains', ['text' => self::staticString('hello world'), 'needle' => self::staticString('lo wo'), 'then' => self::staticString('y'), 'else' => self::staticString('n')], 'y'];
    yield 'contains: starts_with' => ['contains', ['text' => self::staticString('hello world'), 'needle' => self::staticString('hello'), 'position' => self::staticString('starts_with'), 'then' => self::staticString('y'), 'else' => self::staticString('n')], 'y'];
    yield 'contains: ends_with, no match' => ['contains', ['text' => self::staticString('hello world'), 'needle' => self::staticString('hello'), 'position' => self::staticString('ends_with'), 'then' => self::staticString('y'), 'else' => self::staticString('n')], 'n'];
    yield 'contains: negated' => ['contains', ['text' => self::staticString('hello world'), 'needle' => self::staticString('xyz'), 'negate' => self::staticBoolean(TRUE), 'then' => self::staticString('y'), 'else' => self::staticString('n')], 'y'];

    // Mapping: the "option value drives a component variant" scenario.
    $cases = self::staticString('{"blue":"primary","red":"danger"}');
    yield 'mapping: match' => ['mapping', ['value' => self::staticString('blue'), 'cases' => $cases, 'default' => self::staticString('neutral')], 'primary'];
    yield 'mapping: unmatched value falls back to default' => ['mapping', ['value' => self::staticString('green'), 'cases' => $cases, 'default' => self::staticString('neutral')], 'neutral'];
    yield 'mapping: unmatched value without default' => ['mapping', ['value' => self::staticString('green'), 'cases' => $cases], NULL];
    yield 'mapping: invalid cases JSON falls back to default' => ['mapping', ['value' => self::staticString('blue'), 'cases' => self::staticString('not json'), 'default' => self::staticString('neutral')], 'neutral'];

    // Combine: the "first name + last name" scenario; empty inputs are
    // skipped along with their separator.
    yield 'combine: two inputs, default separator' => ['combine', ['text_1' => self::staticString('John'), 'text_2' => self::staticString('Doe')], 'John Doe'];
    yield 'combine: empty input skipped' => ['combine', ['text_1' => self::staticString('John'), 'text_2' => self::staticString(''), 'text_3' => self::staticString('Doe')], 'John Doe'];
    yield 'combine: custom separator' => ['combine', ['text_1' => self::staticString('a'), 'text_2' => self::staticString('b'), 'separator' => self::staticString(' | ')], 'a | b'];
    yield 'combine: all inputs empty' => ['combine', ['text_1' => self::staticString(''), 'text_2' => self::staticString('')], NULL];

    // Date conversion: empty and unparseable input adapt to nothing.
    yield 'format_date: empty date' => ['format_date', ['date' => self::staticString(''), 'format' => self::staticString('relative')], NULL];
    yield 'format_date: unparseable date' => ['format_date', ['date' => self::staticString('not a date'), 'format' => self::staticString('relative')], NULL];
  }

  /**
   * Date conversion, absolute mode: formats via date format config entities.
   */
  public function testFormatDateAbsolute(): void {
    DateFormat::create([
      'id' => 'canvas_test_format',
      'label' => 'Canvas test format',
      'pattern' => 'M j, Y',
    ])->save();

    $source = PropSource::parse([
      'sourceType' => 'adapter:format_date',
      'adapterInputs' => [
        'date' => self::staticString('2024-05-06T12:00:00'),
        'format' => self::staticString('canvas_test_format'),
      ],
    ]);
    $result = $source->evaluate(NULL, is_required: FALSE);
    $this->assertSame('May 6, 2024', $result->value);
    // The output depends on the date format config entity, whose cache tag is
    // core's coarse `rendered` tag: changing a date format invalidates all
    // rendered output.
    // @see \Drupal\Core\Datetime\Entity\DateFormat::getCacheTagsToInvalidate()
    $this->assertContains('rendered', $result->getCacheTags());

    // An unknown date format adapts to nothing rather than failing the render.
    $source = PropSource::parse([
      'sourceType' => 'adapter:format_date',
      'adapterInputs' => [
        'date' => self::staticString('2024-05-06T12:00:00'),
        'format' => self::staticString('nonexistent_format'),
      ],
    ]);
    $this->assertNull($source->evaluate(NULL, is_required: FALSE)->value);
  }

  /**
   * Date conversion, relative mode: "2 days ago" and "in 2 days".
   */
  public function testFormatDateRelative(): void {
    // TRICKY: datetime field values are stored in UTC, and the adapter
    // parses them as UTC — so the test must generate the string in UTC too.
    $two_days_ago = \gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime() - 2 * 86400 - 60);
    $source = PropSource::parse([
      'sourceType' => 'adapter:format_date',
      'adapterInputs' => [
        'date' => self::staticString($two_days_ago),
        'format' => self::staticString(FormatDateAdapter::FORMAT_RELATIVE),
      ],
    ]);
    $result = $source->evaluate(NULL, is_required: FALSE);
    $this->assertSame('2 days ago', $result->value);
    // A relative phrase goes stale as time passes, so it must carry a finite
    // max-age.
    $this->assertNotSame(Cache::PERMANENT, $result->getCacheMaxAge());

    $in_two_days = \gmdate('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime() + 2 * 86400 + 60);
    $source = PropSource::parse([
      'sourceType' => 'adapter:format_date',
      'adapterInputs' => [
        'date' => self::staticString($in_two_days),
        'format' => self::staticString(FormatDateAdapter::FORMAT_RELATIVE),
      ],
    ]);
    $this->assertSame('in 2 days', $source->evaluate(NULL, is_required: FALSE)->value);
  }

  /**
   * Parametric adapters match any target prop shape (design D3).
   *
   * @see \Drupal\canvas\Plugin\Adapter\Adapter::$outputMirrorsInputs
   * @see \Drupal\canvas\Plugin\AdapterManager::getDefinitionsByOutputSchema()
   */
  public function testParametricOutputMatching(): void {
    $adapter_manager = $this->container->get(AdapterManager::class);
    \assert($adapter_manager instanceof AdapterManager);
    $parametric = ['contains', 'equals', 'fallback', 'mapping'];

    $cases = [
      // A shape no adapter declares as its static output.
      [['type' => 'string', 'format' => 'uri'], []],
      [['type' => 'string'], ['combine', 'format_date', 'prefix_suffix']],
      [['type' => 'boolean'], ['is_set']],
      [['type' => 'integer'], ['day_count']],
    ];
    foreach ($cases as [$schema, $expected_shape_specific]) {
      $matched = $adapter_manager->getDefinitionsByOutputSchema($schema);
      $matched_ids = \array_map(fn (AdapterInterface $a): string => $a->getPluginId(), $matched);
      foreach ($parametric as $parametric_id) {
        $this->assertContains($parametric_id, $matched_ids, \sprintf('Parametric adapter `%s` matches %s.', $parametric_id, \json_encode($schema)));
      }
      $this->assertSame($expected_shape_specific, \array_values(\array_diff($matched_ids, $parametric)), \sprintf('Shape-specific matches for %s.', \json_encode($schema)));
      // Every match self-reports as matching.
      foreach ($matched as $adapter) {
        $this->assertTrue($adapter->matchesOutputSchema($schema));
      }
    }

    // The parametric adapters bind their mirroring inputs.
    $equals = $adapter_manager->createInstance('equals');
    \assert($equals instanceof AdapterInterface);
    $this->assertSame(['then', 'else'], $equals->getOutputMirroringInputs());
    $unix_to_date = $adapter_manager->createInstance('unix_to_date');
    \assert($unix_to_date instanceof AdapterInterface);
    $this->assertSame([], $unix_to_date->getOutputMirroringInputs());
  }

  /**
   * The Adapter attribute enforces exactly one way of declaring the output.
   */
  public function testAdapterAttributeOutputValidation(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The `broken` adapter must declare exactly one of `output` or `outputMirrorsInputs`.');
    new Adapter(
      id: 'broken',
      label: new TranslatableMarkup('Broken'),
      inputs: ['value' => []],
      requiredInputs: ['value'],
    );
  }

}
