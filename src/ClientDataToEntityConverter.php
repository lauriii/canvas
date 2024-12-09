<?php

namespace Drupal\experience_builder;

use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\Validator\ConstraintViolationInterface;

// phpcs:disable
// @todo Remove this — this was added to keep the logic here simple until it is evolved to support all ComponentSource plugins' inputs.
final class InvalidRequestBodyValue extends \Exception {
  public function __construct(
    string $message,
    public readonly ?string $propertyPath = NULL,
  ) {
    $this->message = $message;
  }
}
// phpcs:enable

class ClientDataToEntityConverter {

  use ClientServerConversionTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function convert(array $client_data, FieldableEntityInterface $entity): EntityConstraintViolationListInterface {
    assert($entity->hasField('field_xb_demo'));
    // @todo Security hardening: any key besides `layout` and `model` should trigger an error response.
    // @todo Allow more keys when allowing data other than the XB component tree to be edited through the XB UI! See the `2.1. Content editing of meta fields` requirement, due for the 0.2 milestone: https://www.drupal.org/project/experience_builder/issues/3455753
    ['layout' => $layout, 'model' => $model] = $client_data;

    [$tree, $props, $violations] = $this->convertClientToServer($layout, $model);
    if ($violations->count() > 0) {
      return new EntityConstraintViolationList($entity, iterator_to_array($violations));
    }

    $item = $entity->get('field_xb_demo')->first();
    assert($item instanceof ComponentTreeItem);
    $item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
      'props' => json_encode($props, JSON_UNESCAPED_UNICODE),
    ]);
    $original_entity_violations = $entity->validate();
    // Validation happens using the server-side representation, but the
    // error message should use the client-side representation received in
    // the request body.
    // @see ::clientLayoutToServerTree()
    // @see ::clientModelToServerProps()
    $transformed_violations = new EntityConstraintViolationList($entity, array_map(
      fn (ConstraintViolationInterface $v) => match (TRUE) {
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.tree[' . ComponentTreeStructure::ROOT_UUID . ']') => self::violationWithPropertyPathReplacePrefix($v, 'field_xb_demo.0.tree[' . ComponentTreeStructure::ROOT_UUID . ']', 'layout.children'),
        // @todo Perform a more complex transformation to accurately point to non-root-level components, OR remove the need for that in https://www.drupal.org/project/experience_builder/issues/3467954
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.tree') => self::violationWithPropertyPathReplacePrefix($v, "field_xb_demo.0.tree", 'layout'),
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.props') => self::violationWithPropertyPathReplacePrefix($v, "field_xb_demo.0.props", 'model'),
        default => $v,
      },
      iterator_to_array($original_entity_violations),
    ));
    return $transformed_violations;
  }

}
