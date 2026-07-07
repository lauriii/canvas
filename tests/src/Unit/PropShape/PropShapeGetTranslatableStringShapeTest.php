<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\PropShape;

use Drupal\canvas\PropShape\PropShape;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @see \Drupal\Tests\canvas\Unit\PropShape\PropShapeIsPlainOrRichProseTest
 */
#[CoversClass(PropShape::class)]
#[CoversMethod(PropShape::class, 'getTranslatableStringShape')]
#[Group('canvas')]
#[Group('canvas_translation')]
final class PropShapeGetTranslatableStringShapeTest extends UnitTestCase {

  /**
   * The single source of truth for which prop shapes hold translatable text.
   *
   * Only plain strings (single- and multi-line), rich (HTML) strings and
   * URI-esque strings are translatable. Everything else — dates, numbers,
   * booleans, emails and enums — is not. Cardinality is irrelevant: an array of
   * translatable items is translatable.
   *
   * The returned shape is what a component version retains in its
   * `prop_field_definitions` (`string_shape`), reduced to the keys that matter
   * for translation systems; `type: string` is implied.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::isExplicitInputTranslatable()
   * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
   */
  #[DataProvider('providerGetTranslatableStringShape')]
  public function testGetTranslatableStringShape(?array $expected, array $prop_shape): void {
    $this->assertSame(
      $expected,
      PropShape::getTranslatableStringShape($prop_shape),
    );
  }

  public static function providerGetTranslatableStringShape(): \Generator {
    // Translatable: plain prose.
    yield 'plain string' => [[], ['type' => 'string']];
    // Note: the first column is the stored `string_shape`, and `[]` means it's a plain prose string without other constraints.
    yield 'plain string, SDC-appended object type' => [[], ['type' => ['string', 'object']]];
    // A multi-line PLAIN string (maps to `string_long`) is translatable too.
    yield 'multi-line plain string (string_long)' => [['pattern' => '(.|\r?\n)*'], ['type' => 'string', 'pattern' => '(.|\r?\n)*']];

    // Translatable: rich prose. Real HTML props always carry an
    // `x-formatting-context` (discovery guarantees a valid one); both `block`
    // and `inline` are translatable. The distinction does not affect
    // translatability but IS retained: translation systems must not let an
    // `inline` prop receive block-level HTML.
    yield 'HTML string, block' => [['contentMediaType' => 'text/html', 'x-formatting-context' => 'block'], ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block']];
    yield 'HTML string, inline' => [['contentMediaType' => 'text/html', 'x-formatting-context' => 'inline'], ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']];

    // Translatable: URI-esque strings.
    yield 'uri' => [['format' => 'uri'], ['type' => 'string', 'format' => 'uri']];
    yield 'uri-reference' => [['format' => 'uri-reference'], ['type' => 'string', 'format' => 'uri-reference']];
    yield 'iri' => [['format' => 'iri'], ['type' => 'string', 'format' => 'iri']];
    // Additional constraints do not affect translatability and are not
    // retained.
    yield 'uri-reference with pattern' => [['format' => 'uri-reference'], ['type' => 'string', 'format' => 'uri-reference', 'pattern' => '^/']];

    // Translatable: arrays peek at their item shape.
    yield 'array of plain strings' => [[], ['type' => 'array', 'items' => ['type' => 'string']]];
    yield 'array of HTML strings' => [['contentMediaType' => 'text/html', 'x-formatting-context' => 'inline'], ['type' => 'array', 'items' => ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']]];

    // NOT translatable: non-prose scalars.
    yield 'email' => [NULL, ['type' => 'string', 'format' => 'email']];
    yield 'date' => [NULL, ['type' => 'string', 'format' => 'date']];
    yield 'date-time' => [NULL, ['type' => 'string', 'format' => 'date-time']];
    yield 'integer' => [NULL, ['type' => 'integer']];
    yield 'number' => [NULL, ['type' => 'number']];
    yield 'boolean' => [NULL, ['type' => 'boolean']];
    yield 'enum string' => [NULL, ['type' => 'string', 'enum' => ['a', 'b']]];

    // NOT translatable: arrays of non-prose items.
    yield 'array of integers' => [NULL, ['type' => 'array', 'items' => ['type' => 'integer']]];
    yield 'array of emails' => [NULL, ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']]];
  }

}
