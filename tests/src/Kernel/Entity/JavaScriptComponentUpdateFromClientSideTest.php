<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Entity;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropExpressions\StructuredData\Coalescer;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Component\Assertion\Inspector;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
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
 * ::testMultiBundleBranchReferenceValidates installs real entity types to prove
 * a coalesced multi-bundle branch reference (a prop from each target bundle) is
 * accepted, not rejected.
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
    // scalar) un-combined: the image picks combine into one object reference,
    // the video pick stays a separate scalar reference. No multi-bundle-specific
    // constraint rejects them; both are left un-combined on the same field, so
    // the general same-field coalescing constraint still flags that pair (they
    // must become a single branch to be storable).
    yield 'shape mismatch across bundles stays un-combined' => [
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟alt',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟width',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟target_id',
      ],
      [
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝thumbnail␞␟{alt↠alt,width↠width}',
        'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝thumbnail␞␟target_id',
      ],
      ['dataDependencies.entityFields.article_ref' => "Multiple expressions on the same field 'entity:node:article.field_media' must be coalesced into a single FieldObjectPropsExpression."],
    ];

    // Acceptance of a coalesced multi-bundle branch reference (the former
    // save-time rejection is gone) needs the referenced entity types to exist,
    // so it is proven with real fixtures in
    // ::testMultiBundleBranchReferenceValidates() rather than here.
  }

  /**
   * A coalesced multi-bundle branch reference validates: it is accepted.
   *
   * The client sends two single-bundle picks on the same reference field but
   * different bundles — one prop from the image branch, one from the video
   * branch. They coalesce into a single `ReferencedBundleSpecificBranches`
   * reference. With the multi-target-bundle save-time rejection removed, that
   * stored branch reference now validates instead of being rejected. Real
   * entity types are installed here (unlike ::providerCoalesceValidation) so
   * the branch is validated for real, not masked by "entity type does not
   * exist".
   */
  public function testMultiBundleBranchReferenceValidates(): void {
    self::installMultiBundleReferenceFixtures();

    // Two props from different branches: an image field and a video field.
    $component = self::createComponentWithEntityFields([
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝field_media_image␞␟alt',
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝field_media_video_file␞␟target_id',
    ]);

    // The two picks coalesced into one branch reference.
    self::assertSame(
      ['ℹ︎␜entity:node:article␝field_media␞␟entity␜[␜entity:media:image␝field_media_image␞␟alt][␜entity:media:video␝field_media_video_file␞␟target_id]'],
      $component->get('dataDependencies')['entityFields'][self::PROP_NAME],
    );

    // The branch reference is accepted: no violation anywhere in entityFields.
    self::assertSame([], self::entityFieldViolations($component));
  }

  /**
   * Multiple picks per bundle across bundles coalesce into one branch.
   *
   * The client sends two picks on one target bundle (which combine into an
   * object) plus a pick on another bundle. All three are on the same reference
   * field, so they must coalesce into a single branch expression. Today they do
   * not: they coalesce into one FieldObjectPropsExpression that mixes
   * bundle-specific properties, which is unrenderable (it would fatal against
   * the non-matching bundle) and is rejected at save time by
   * EntityFieldExpressionsMustNotMixBundlesInObject.
   *
   * @todo Passes once these picks coalesce into a branch of per-bundle objects — https://git.drupalcode.org/project/canvas/-/work_items/3591873
   */
  public function testMultiBundleBranchReferenceWithMultipleFieldsPerBundle(): void {
    $this->markTestSkipped('Multiple fields per bundle do not yet coalesce into a branch — https://git.drupalcode.org/project/canvas/-/work_items/3591873');

    // @phpstan-ignore deadCode.unreachable
    self::installMultiBundleReferenceFixtures();

    // Two picks on the image bundle (they object-combine) + one on video.
    $component = self::createComponentWithEntityFields([
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝field_media_image␞␟alt',
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝field_media_video_file␞␟target_id',
    ]);

    // All picks on field_media coalesce into ONE branch expression …
    $stored = $component->get('dataDependencies')['entityFields'][self::PROP_NAME];
    self::assertCount(1, $stored, 'Per-bundle picks did not coalesce into a single branch expression.');
    self::assertStringContainsString('␜[', (string) $stored[0], 'The coalesced entry is not a bundle-specific branch expression.');

    // … so nothing is left for the same-field coalescing constraint to flag.
    self::assertSame([], self::entityFieldViolations($component));
  }

  /**
   * Reading multiple/differing fields per bundle is rejected at save time.
   *
   * These picks coalesce into one FieldObjectPropsExpression that mixes
   * bundle-specific properties (an image field and a video field on the same
   * object). That object is unrenderable: at render each property is evaluated
   * against the one resolved entity, and a single-bundle property throws for
   * every other bundle. Until it coalesces into a branch of per-bundle objects
   * (see the skipped test above), EntityFieldExpressionsMustNotMixBundlesInObject
   * rejects it so it cannot be persisted.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\EntityFieldExpressionsMustNotMixBundlesInObjectConstraintValidator
   */
  public function testMultiBundleObjectMixingBundlesIsRejected(): void {
    self::installMultiBundleReferenceFixtures();
    $component = self::createComponentWithEntityFields([
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝name␞␟value',
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:image␝field_media_image␞␟alt',
      'ℹ︎␜entity:node:article␝field_media␞␟entity␜␜entity:media:video␝field_media_video_file␞␟target_id',
    ]);
    self::assertSame(
      [
        'dataDependencies.entityFields.article_ref' => "The expressions on field 'entity:node:article.field_media' read several fields from more than one bundle of the same reference, which is not yet supported.",
      ],
      self::entityFieldViolations($component),
    );
  }

  /**
   * Installs a node + two media types + a multi-bundle reference field.
   *
   * `field_media` on `article` targets both the `image` and `video` media
   * bundles, so a code component can pick fields from each branch.
   */
  private function installMultiBundleReferenceFixtures(): void {
    // Enabled only here (each test runs in a separate process): enabling `node`
    // globally would make the Coalescer stop combining different-bundle picks
    // in the fixture-free coalescing providers.
    $this->enableModules(['field', 'node']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('media');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    // Two media types so one reference field can target multiple bundles.
    self::createMediaType('image', 'image');
    self::createMediaType('video', 'video_file');

    // A multi-bundle reference field: field_media on article → image + video.
    FieldStorageConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Media',
      'settings' => ['handler_settings' => ['target_bundles' => ['image' => 'image', 'video' => 'video']]],
    ])->save();
  }

  /**
   * Returns validation messages keyed by property path under entityFields.
   *
   * @return array<string, string>
   */
  private static function entityFieldViolations(JavaScriptComponent $component): array {
    $violations = [];
    foreach ($component->getTypedData()->validate() as $violation) {
      $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return \array_filter(
      $violations,
      static fn (string $path): bool => \str_starts_with($path, 'dataDependencies.entityFields'),
      \ARRAY_FILTER_USE_KEY,
    );
  }

  /**
   * Creates a media type with its source field, for reference-field fixtures.
   */
  private static function createMediaType(string $id, string $source): void {
    $media_type = MediaType::create(['id' => $id, 'label' => \ucfirst($id), 'source' => $source]);
    $media_type->save();
    $source_field = $media_type->getSource()->createSourceField($media_type);
    $source_field_storage = $source_field->getFieldStorageDefinition();
    \assert($source_field_storage instanceof FieldStorageConfigInterface);
    if (!FieldStorageConfig::loadByName('media', $source_field->getName())) {
      $source_field_storage->save();
    }
    $source_field->save();
    $media_type->set('source_configuration', ['source_field' => $source_field->getName()])->save();
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
