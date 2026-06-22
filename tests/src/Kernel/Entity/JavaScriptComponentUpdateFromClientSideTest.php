<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Component\Assertion\Inspector;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity-field coalescing inside ::updateFromClientSide and its inverse.
 *
 * An entire dedicated test for a subset of ::updateFromClientSide() may seem
 * excessive, but it is by far the most complex logic on the server that enables
 * the client (UI) to remain simple.
 *
 * @see docs/adr/0005-Keep-the-front-end-simple.md
 *
 * Three data providers cover the behavior, each case named by the pattern it
 * locks:
 * - ::providerCoalesce — the client's atomic per-property picks coalesce into
 *   the stored entries (loose leaves, reference chains, and multi-bundle
 *   branches; order-independent).
 * - ::providerCoalesceValidation — coalescing is a pure transform; what it
 *   cannot combine (same-property collisions, multi-bundle references) is left
 *   for validation to flag or reject.
 * - ::providerNormalizeExpands — stored entries expand back to the atomic picks
 *   the client sent.
 *
 * @legacy-covers \Drupal\canvas\Entity\JavaScriptComponent::updateFromClientSide
 * @legacy-covers \Drupal\canvas\Entity\JavaScriptComponent::normalizeForClientSide
 */
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[RunTestsInSeparateProcesses]
final class JavaScriptComponentUpdateFromClientSideTest extends CanvasKernelTestBase {

  private const string PROP_NAME = 'article_ref';

  /**
   * Coalescing transforms the client's atomic picks into stored entries.
   *
   * @param list<string> $input
   *   The atomic per-property expressions the client sends.
   * @param list<string> $expected_stored
   *   The coalesced entries stored on the entity.
   */
  #[DataProvider('providerCoalesce')]
  public function testCoalesce(array $input, array $expected_stored): void {
    $stored = self::createComponentWithEntityFields($input)
      ->get('dataDependencies')['entityFields'][self::PROP_NAME];
    // Output order is not part of the contract, so compare as sets.
    \sort($stored);
    \sort($expected_stored);
    self::assertSame($expected_stored, $stored);
  }

  /**
   * Provides coalescing scenarios, keyed by the pattern under test.
   *
   * Literal strings: this test forks a process, which serializes data-provider
   * arguments, so closures are unavailable.
   *
   * @return iterable<string, array{list<string>, list<string>}>
   */
  public static function providerCoalesce(): iterable {
    // Two loose leaves on the same field → one combined entry.
    yield 'two loose leaves combine' => [
      [
        'ℹ︎␜entity:media:image␝field_media_image␞␟srcset_candidate_uri_template',
        'ℹ︎␜entity:media:image␝field_media_image␞␟width',
      ],
      ['ℹ︎␜entity:media:image␝field_media_image␞␟{srcset_candidate_uri_template↠srcset_candidate_uri_template,width↠width}'],
    ];

    // A lone loose leaf is stored verbatim, not wrapped.
    yield 'lone loose leaf unchanged' => [
      ['ℹ︎␜entity:media:image␝field_media_image␞␟width'],
      ['ℹ︎␜entity:media:image␝field_media_image␞␟width'],
    ];

    // A standalone already-combined object is idempotent.
    yield 'lone combined object unchanged' => [
      ['ℹ︎␜entity:media:image␝field_media_image␞␟{srcset_candidate_uri_template↠srcset_candidate_uri_template,width↠width}'],
      ['ℹ︎␜entity:media:image␝field_media_image␞␟{srcset_candidate_uri_template↠srcset_candidate_uri_template,width↠width}'],
    ];

    // An existing combined entry + a loose leaf on the same field merge,
    // order-independent (both orderings yield the same combined entry).
    $existing = 'ℹ︎␜entity:media:image␝field_media_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,srcset↠srcset_candidate_uri_template}';
    $loose = 'ℹ︎␜entity:media:image␝field_media_image␞␟width';
    $merged = ['ℹ︎␜entity:media:image␝field_media_image␞␟{src↝entity␜␜entity:file␝uri␞␟url,srcset↠srcset_candidate_uri_template,width↠width}'];
    yield 'existing combined + loose merge (order A)' => [[$existing, $loose], $merged];
    yield 'existing combined + loose merge (order B)' => [[$loose, $existing], $merged];

    // Two reference-chain picks on the same final field combine into one
    // reference whose final target is an object.
    yield 'two reference-chain picks on same final field combine' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,width↠width}'],
    ];

    // Two reference-chain picks on different final fields of the same bundle,
    // with no loose pick on that field, combine into one object.
    yield 'reference-chain picks on different final fields combine into one object' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝mail␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝uid␞␟{mail↝entity␜␜entity:user␝mail␞␟value,name↝entity␜␜entity:user␝name␞␟value}'],
    ];

    // An existing combined reference-chain target + a loose reference on the
    // same final field merge, order-independent.
    $ec = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,width↠width}';
    $lh = 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟height';
    $rcm = ['ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟{alt↠alt,height↠height,width↠width}'];
    yield 'existing combined reference-chain + loose merge (order A)' => [[$ec, $lh], $rcm];
    yield 'existing combined reference-chain + loose merge (order B)' => [[$lh, $ec], $rcm];

    // Two single-bundle references on different bundles combine into a single
    // multi-bundle branch reference.
    yield 'different-bundle references combine into branches' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]'],
    ];

    // Object-prop coalescing runs first within each bundle, then branch
    // coalescing combines the bundles.
    yield 'object props and branches combine' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟width',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟width',
      ],
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝thumbnail␞␟{alt↠alt,width↠width}][␜entity:media:video␝thumbnail␞␟{alt↠alt,width↠width}]'],
    ];
  }

  /**
   * Coalescing is a pure transform; validation surfaces what it cannot combine.
   *
   * @param list<string> $input
   *   The atomic per-property expressions the client sends.
   * @param list<string> $expected_stored
   *   The coalesced entries stored on the entity.
   * @param array<string, string|null> $expected_violations
   *   The expected validation message at each property path, or NULL to assert
   *   no violation at that path. Other paths are not asserted: the entity types
   *   referenced here are not installed (this test exercises the coalescing
   *   structure, not entity validity), so "entity type does not exist"
   *   violations are present and irrelevant.
   */
  #[DataProvider('providerCoalesceValidation')]
  public function testCoalesceValidation(array $input, array $expected_stored, array $expected_violations): void {
    $component = self::createComponentWithEntityFields($input);
    $stored = $component->get('dataDependencies')['entityFields'][self::PROP_NAME];
    \sort($stored);
    \sort($expected_stored);
    self::assertSame($expected_stored, $stored);

    $messages = [];
    foreach ($component->getTypedData()->validate() as $violation) {
      $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    foreach ($expected_violations as $path => $message) {
      self::assertSame($message, $messages[$path] ?? NULL, "Violation on '$path'");
    }
  }

  /**
   * Provides scenarios the validator (not the coalescing) is responsible for.
   *
   * Keyed by the pattern under test.
   *
   * @return iterable<string, array{list<string>, list<string>, array<string, string|null>}>
   */
  public static function providerCoalesceValidation(): iterable {
    // A combined entry and a loose leaf colliding on the same property pass
    // through verbatim and are flagged for coalescing.
    yield 'loose same-property collision' => [
      [
        'ℹ︎␜entity:media:image␝field_media_image␞␟{srcset_candidate_uri_template↠srcset_candidate_uri_template,width↠width}',
        'ℹ︎␜entity:media:image␝field_media_image␞␟width',
      ],
      [
        'ℹ︎␜entity:media:image␝field_media_image␞␟{srcset_candidate_uri_template↠srcset_candidate_uri_template,width↠width}',
        'ℹ︎␜entity:media:image␝field_media_image␞␟width',
      ],
      ['dataDependencies.entityFields.article_ref' => "Multiple expressions on the same field 'entity:media:image.field_media_image' must be coalesced into a single FieldObjectPropsExpression."],
    ];

    // Two reference-chain picks on the same final sub-property are true
    // duplicates: pass through verbatim, flagged by the FINAL target field.
    yield 'reference-chain same-property collision' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
      ],
      ['dataDependencies.entityFields.article_ref' => "Multiple expressions on the same field 'entity:node:article.uid' must be coalesced into a single FieldObjectPropsExpression."],
    ];

    // Branch coalescing leaves bundles whose leaf shapes differ (object vs
    // scalar) un-combined; no shape-match constraint is forced.
    // @todo This situation was tested before, but cannot occur: the UI does not offer it, the back end does not accept it; support will be added in https://git.drupalcode.org/project/canvas/-/work_items/3591656
    // @see \Drupal\Tests\canvas\Kernel\Controller\ApiUiContentEntityReferenceControllersTest::testFieldsEndpointMultiTargetBundleReferenceField
    // @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\JsComponentTest::testMultiTargetBundleReferenceThrows
    // @phpcs:disable
    /*
    yield 'shape mismatch across bundles is not rejected' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟width',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟target_id',
      ],
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟{alt↠alt,width↠width}',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟target_id',
      ],
      // No coalescing violation: the differing leaf shapes are left un-combined.
      ['dataDependencies.entityFields.article_ref' => NULL],
    ];
    */
    // @phpcs:enable

    // A coalesced multi-bundle branch reference is rejected at render time.
    yield 'multi-bundle branch reference is rejected' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value',
      ],
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝name␞␟value][␜entity:media:video␝name␞␟value]'],
      ['dataDependencies.entityFields.article_ref.0' => "The reference field 'entity:node:article.field_media' targets multiple bundles, which is not yet supported."],
    ];
  }

  /**
   * Normalize-for-client expands coalesced entries back to their atomic leaves.
   *
   * The client sends atomic per-property picks; they coalesce into combined
   * entries at save time and expand back to the same atomic picks on the wire,
   * keeping the client out of expression-string parsing. The round trip is the
   * identity for every shape the picker produces.
   *
   * @param list<string> $atomic_leaves
   *   The atomic per-property expressions the client sends.
   */
  #[DataProvider('providerNormalizeExpands')]
  public function testNormalizeExpands(array $atomic_leaves): void {
    $component = self::createComponentWithEntityFields($atomic_leaves);
    $expanded = self::expandEntityFields($component)['entityFields'][self::PROP_NAME];

    // Output order is not part of the contract, so compare as sets.
    \sort($atomic_leaves);
    \sort($expanded);
    self::assertSame($atomic_leaves, $expanded);
  }

  /**
   * Provides expand round-trip scenarios, keyed by the shape under test.
   *
   * @return iterable<string, array{list<string>}>
   */
  public static function providerNormalizeExpands(): iterable {
    // Two loose picks on the same field → one combined entry → back to leaves.
    yield 'homogeneous loose leaves' => [
      [
        'ℹ︎␜entity:media:image␝field_media_image␞␟srcset_candidate_uri_template',
        'ℹ︎␜entity:media:image␝field_media_image␞␟width',
      ],
    ];

    // Two reference-chain picks on the same final field → one combined
    // reference entry → back to leaves.
    yield 'homogeneous reference chain' => [
      [
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟alt',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝user_picture␞␟width',
      ],
    ];

    // A leaf and a reference descending through the same field → one combined
    // object entry → back to leaves. Object-prop names stay canonical (`uri`
    // is getDeveloperFacingKey(file.uri), `width` the field property), so the
    // round trip is lossless.
    yield 'mix of leaf and reference on one field' => [
      [
        'ℹ︎␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url',
        'ℹ︎␜entity:media:image␝field_media_image␞␟width',
      ],
    ];

    // Solo entries on different fields do not coalesce and pass through.
    yield 'solo atomic entries pass through' => [
      [
        'ℹ︎␜entity:node:article␝title␞␟value',
        'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
      ],
    ];

    // Two single-bundle references → one multi-bundle branch entry → back to
    // the per-bundle references.
    yield 'multi-bundle branches' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝name␞␟value',
      ],
    ];

    // Doubly-coalesced (branches + object props) → back to the four picks.
    yield 'multi-bundle branches with object props' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟width',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟width',
      ],
    ];
  }

  /**
   * Builds a minimal JavaScriptComponent via the production code path.
   *
   * @param list<string> $expression_strings
   *
   * @return \Drupal\canvas\Entity\JavaScriptComponent
   */
  private static function createComponentWithEntityFields(array $expression_strings): JavaScriptComponent {
    $entity = JavaScriptComponent::createFromClientSide([
      'machineName' => 'coalesce_test',
      'name' => 'Coalesce test',
      'status' => TRUE,
      'props' => [
        self::PROP_NAME => [
          'title' => 'Article ref',
          ...JsonSchemaObjectRef::ContentEntityReference->asPropShapeArray(),
        ],
      ],
      'required' => [],
      'slots' => [],
      'dataDependencies' => [
        'entityFields' => [
          self::PROP_NAME => $expression_strings,
        ],
      ],
    ]);
    return $entity;
  }

  /**
   * Returns the expanded `dataDependencies` the client would receive.
   *
   * Mirrors what `JavaScriptComponent::expandEntityFields()` does on the
   * client path, via the same public `Coalescer::expand()` infrastructure.
   *
   * @return array<string, mixed>
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer::expand()
   */
  private static function expandEntityFields(JavaScriptComponent $component): array {
    $dataDependencies = $component->get('dataDependencies') ?? [];
    foreach ($dataDependencies['entityFields'] ?? [] as $prop_name => $expression_strings) {
      \assert(\is_array($expression_strings) && \array_is_list($expression_strings));
      \assert(Inspector::assertAllStrings($expression_strings));
      $expressions = \array_map(StructuredDataPropExpression::fromString(...), $expression_strings);
      \assert(Inspector::assertAllObjects($expressions, EntityFieldBasedPropExpressionInterface::class));
      $dataDependencies['entityFields'][$prop_name] = \array_map(
        static fn (EntityFieldBasedPropExpressionInterface $expression): string => (string) $expression,
        Coalescer::expand($expressions),
      );
    }
    return $dataDependencies;
  }

}
