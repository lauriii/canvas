<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that an entity's exposed slot content can merge into its template.
 *
 * Each exposed slot field's component instances must have UUIDs that do not
 * occur in the template's tree (outside the slot's default content) nor in
 * another exposed slot field. Otherwise, rendering falls back to the template's
 * default content for that slot.
 *
 * @see \Drupal\canvas\Entity\ContentTemplate::getMergedComponentTree()
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::injectSlotContent()
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Exposed slot content is mergeable', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class ExposedSlotContentIsMergeableConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ExposedSlotContentIsMergeable';

  #[HasNamedArguments]
  public function __construct(
    // The violation message when a slot field's component instances collide
    // with the template or another exposed slot.
    public string $message = 'The %field exposed slot contains component instances that already exist in the template or in another exposed slot.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
