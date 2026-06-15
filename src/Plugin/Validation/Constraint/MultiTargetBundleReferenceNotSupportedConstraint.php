<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects entity field expressions that reference more than one target bundle.
 *
 * An entity reference field targeting multiple bundles coalesces into a
 * ReferenceFieldPropExpression with ReferencedBundleSpecificBranches. Resolving
 * those at render time is not yet supported (it throws in
 * JsComponent::buildReferencePayload()), so storing them is rejected for now.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::buildReferencePayload()
 * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591656
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Multi-target-bundle references are not supported.", [], ['context' => 'Validation']),
  type: "string",
)]
final class MultiTargetBundleReferenceNotSupportedConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'MultiTargetBundleReferenceNotSupported';

  /**
   * The error message.
   */
  public string $message = "The reference field '@field' targets multiple bundles, which is not yet supported.";

}
