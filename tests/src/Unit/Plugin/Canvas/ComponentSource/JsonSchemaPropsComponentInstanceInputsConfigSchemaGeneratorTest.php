<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @see \Drupal\Tests\canvas\Unit\PropShape\PropShapeIsPlainOrRichProseTest
 */
#[CoversClass(JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class)]
#[CoversMethod(JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class, 'isTranslatableShape')]
#[Group('canvas')]
#[Group('canvas_translation')]
final class JsonSchemaPropsComponentInstanceInputsConfigSchemaGeneratorTest extends UnitTestCase {

  /**
   * The single source of truth for which prop shapes hold translatable text.
   *
   * Only plain strings (single- and multi-line), rich (HTML) strings and
   * URI-esque strings are translatable. Everything else — dates, numbers,
   * booleans, emails and enums — is not. Cardinality is irrelevant: an array of
   * translatable items is translatable.
   *
   * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
   */
  #[DataProvider('providerIsTranslatableShape')]
  public function testIsTranslatableShape(bool $expected, array $prop_shape): void {
    $this->assertSame(
      $expected,
      JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::isTranslatableShape($prop_shape),
    );
  }

  public static function providerIsTranslatableShape(): \Generator {
    // Translatable: plain prose.
    yield 'plain string' => [TRUE, ['type' => 'string']];
    yield 'plain string, SDC-appended object type' => [TRUE, ['type' => ['string', 'object']]];
    // A multi-line PLAIN string (maps to `string_long`) is translatable too.
    yield 'multi-line plain string (string_long)' => [TRUE, ['type' => 'string', 'pattern' => '(.|\r?\n)*']];

    // Translatable: rich prose. Real HTML props always carry an
    // `x-formatting-context` (discovery guarantees a valid one); both `block`
    // and `inline` are translatable.
    yield 'HTML string, block' => [TRUE, ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block']];
    yield 'HTML string, inline' => [TRUE, ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']];

    // Translatable: URI-esque strings.
    yield 'uri' => [TRUE, ['type' => 'string', 'format' => 'uri']];
    yield 'uri-reference' => [TRUE, ['type' => 'string', 'format' => 'uri-reference']];
    yield 'iri' => [TRUE, ['type' => 'string', 'format' => 'iri']];

    // Translatable: arrays peek at their item shape.
    yield 'array of plain strings' => [TRUE, ['type' => 'array', 'items' => ['type' => 'string']]];
    yield 'array of HTML strings' => [TRUE, ['type' => 'array', 'items' => ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline']]];

    // NOT translatable: non-prose scalars.
    yield 'email' => [FALSE, ['type' => 'string', 'format' => 'email']];
    yield 'date' => [FALSE, ['type' => 'string', 'format' => 'date']];
    yield 'date-time' => [FALSE, ['type' => 'string', 'format' => 'date-time']];
    yield 'integer' => [FALSE, ['type' => 'integer']];
    yield 'number' => [FALSE, ['type' => 'number']];
    yield 'boolean' => [FALSE, ['type' => 'boolean']];
    yield 'enum string' => [FALSE, ['type' => 'string', 'enum' => ['a', 'b']]];

    // NOT translatable: arrays of non-prose items.
    yield 'array of integers' => [FALSE, ['type' => 'array', 'items' => ['type' => 'integer']]];
    yield 'array of emails' => [FALSE, ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']]];
  }

}
