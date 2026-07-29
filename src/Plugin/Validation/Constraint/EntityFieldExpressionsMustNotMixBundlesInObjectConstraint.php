<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects a reference object that mixes bundle-specific picks.
 *
 * Example of a rejected input: an article's `field_media` reference that can
 * hold an *Image* or a *Video* media item, with two picks that read the image's
 * `field_media_image.alt` and the video's `field_media_video_file.display`.
 * Each pick descends into one specific bundle, but together they read different
 * fields from different bundles of the same reference — so they can't all
 * resolve against the one media entity the reference ultimately holds.
 *
 * In detail: when several picks read more than one field per bundle from a
 * multi-bundle reference, they coalesce into a `FieldObjectPropsExpression` on
 * the referencer field, with one object property per field read. If any of
 * those properties is a single-bundle reference (it descends into one specific
 * bundle) while the object references more than one bundle overall, the object
 * is unrenderable: at render time every property is evaluated against the one
 * resolved referenced entity, and a bundle-specific property throws a
 * `\DomainException` whenever the resolved entity is of a different bundle
 * (only multi-bundle *branch* properties are bundle-matched and omitted). Such
 * selections must coalesce into a branch of per-bundle objects instead, which
 * is not yet produced.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::shouldOmitUnmatchedBundle()
 * @todo Remove this constraint (and its validator) once such selections coalesce into a branch, in https://git.drupalcode.org/project/canvas/-/work_items/3591873
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("A reference object must not mix bundle-specific picks.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsMustNotMixBundlesInObjectConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionsMustNotMixBundlesInObject';

  /**
   * The error message.
   */
  public string $message = "The expressions on field '@field' read several fields from more than one bundle of the same reference, which is not yet supported.";

}
