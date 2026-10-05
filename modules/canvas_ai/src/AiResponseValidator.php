<?php

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Service for validating AI-generated component structures.
 */
class AiResponseValidator {

  use ComponentTreeItemListInstantiatorTrait;
  use ConstraintPropertyPathTranslatorTrait;

  /**
   * Constructs a new AiResponseValidator.
   *
   * @param \Drupal\Core\Validation\BasicRecursiveValidatorFactory $validatorFactory
   *   The validator factory.
   * @param \Drupal\Component\Uuid\UuidInterface $uuidService
   *   The UUID service.
   */
  public function __construct(
    protected readonly BasicRecursiveValidatorFactory $validatorFactory,
    protected readonly UuidInterface $uuidService,
  ) {
  }

  /**
   * Validates the component structure.
   *
   * @param array $componentGroups
   *   The component groups to validate.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When validation fails.
   */
  public function validateComponentStructure(array $componentGroups): void {
    // Create a mapping of components to their original paths.
    $pathMapping = [];
    // Some violations are found outside of ComponentTreeItemList->validate():
    // props that do not exist on their component, and primitive-typed props
    // given a value core's own type validation would reject. Collect them
    // during conversion instead.
    $outOfBandViolations = new ConstraintViolationList();

    // Convert YAML structure to Canvas ComponentTreeItem format.
    $componentTreeData = $this->convertToComponentTreeData($componentGroups, NULL, NULL, 'components', $pathMapping, $outOfBandViolations);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue($componentTreeData);
    $violations = $componentTreeItemList->validate();

    if ($violations->count() > 0 || $outOfBandViolations->count() > 0) {
      $translatedViolations = $this->translateConstraintPropertyPathsAndRoot(
        $this->buildPathTranslationMap($componentTreeData, $pathMapping),
        $violations,
        ''
      );
      // Out-of-band violations are built with already-translated paths.
      $translatedViolations->addAll($outOfBandViolations);
      throw new ConstraintViolationException(
        $translatedViolations,
        'Component validation errors'
      );
    }
  }

  /**
   * Converts component groups to component tree data.
   *
   * @param array $componentGroups
   *   The component groups to convert.
   * @param string|null $parentUuid
   *   The parent UUID, if any.
   * @param string|null $slotName
   *   The slot name, if any.
   * @param string $pathPrefix
   *   The path prefix for the current level.
   * @param array &$pathMapping
   *   Reference to path mapping array.
   * @param \Symfony\Component\Validator\ConstraintViolationList $outOfBandViolations
   *   Collects violations found outside of field-level validation.
   *
   * @return array
   *   The converted component tree data.
   */
  private function convertToComponentTreeData(
    array $componentGroups,
    ?string $parentUuid,
    ?string $slotName,
    string $pathPrefix,
    array &$pathMapping,
    ConstraintViolationList $outOfBandViolations,
  ): array {
    $componentTreeData = [];
    foreach ($componentGroups as $groupIndex => $componentGroup) {
      if (!\is_array($componentGroup)) {
        $this->addGarbageInputViolation(
          $outOfBandViolations,
          \sprintf('Component entry %s cannot be processed: it does not contain the component details in the expected YAML format.', \json_encode($componentGroup)),
          \sprintf('%s.%s', $pathPrefix, $groupIndex),
          $componentGroup,
        );
        continue;
      }
      foreach ($componentGroup as $componentId => $componentData) {
        $componentUuid = $this->uuidService->generate();

        $componentPath = \sprintf('%s.%d.[%s]', $pathPrefix, $groupIndex, $componentId);
        $pathMapping[$componentUuid] = $componentPath;

        // Create a temp version if the component does not exist to allow
        // validation to proceed. The constraints will flag invalid components
        // later.
        $component = Component::load($componentId);
        $componentVersion = $component ? $component->getActiveVersion() : "temp-version-$componentUuid";
        $inputs = [];
        if ($component instanceof Component && !empty($componentData['props'])) {
          $clientNormalized = $component->normalizeForClientSide()->values;
          $propSources = $clientNormalized['propSources'] ?? NULL;
          $this->collectUnknownPropViolations(
            $componentId,
            $componentData['props'],
            $propSources,
            $componentPath,
            $outOfBandViolations
          );
          if (\is_array($componentData['props'])) {
            $this->collectPrimitiveTypeViolations(
              $componentId,
              $componentData['props'],
              $propSources,
              $componentPath,
              $outOfBandViolations
            );
            $clientModel['source'] = $propSources ?? [];
            $clientModel['resolved'] = $componentData['props'];
            $inputs = $component->getComponentSource()->clientModelToInput($componentUuid, $component, $clientModel, NULL);
          }
        }

        $componentTreeItem = [
          'uuid' => $componentUuid,
          'component_id' => $componentId,
          'component_version' => $componentVersion,
          'inputs' => $inputs,
        ];
        if ($parentUuid !== NULL) {
          $componentTreeItem['parent_uuid'] = $parentUuid;
          $componentTreeItem['slot'] = $slotName;
        }

        $componentTreeData[] = $componentTreeItem;

        // Process slots recursively.
        if (isset($componentData['slots']) && !\is_array($componentData['slots'])) {
          $this->addGarbageInputViolation(
            $outOfBandViolations,
            \sprintf('The `slots` value %s cannot be processed: each slot name must be a key holding its own list of components.', \json_encode($componentData['slots'])),
            \sprintf('%s.slots', $componentPath),
            $componentData['slots'],
          );
        }
        elseif (isset($componentData['slots'])) {
          foreach ($componentData['slots'] as $slot => $slotComponentGroups) {
            if (!\is_array($slotComponentGroups)) {
              $this->addGarbageInputViolation(
                $outOfBandViolations,
                \sprintf('The `%s` slot value %s cannot be processed: a slot must hold a YAML list of components.', $slot, \json_encode($slotComponentGroups)),
                \sprintf('%s.slots.%s', $componentPath, $slot),
                $slotComponentGroups,
              );
              continue;
            }
            $slotPath = \sprintf('%s.slots.%s', $componentPath, $slot);
            $componentTreeData = array_merge(
              $componentTreeData,
              $this->convertToComponentTreeData(
                $slotComponentGroups,
                $componentUuid,
                $slot,
                $slotPath,
                $pathMapping,
                $outOfBandViolations
              )
            );
          }
        }
      }
    }
    return $componentTreeData;
  }

  /**
   * Collects violations for AI-supplied props a component does not define.
   *
   * @param string $componentId
   *   The component ID.
   * @param mixed $props
   *   The AI-supplied props value.
   * @param array|null $propSources
   *   The component's defined prop sources, or NULL for sources that are not
   *   prop-based (e.g. block components).
   * @param string $componentPath
   *   The component path, used to build the violation property path.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add violations to.
   */
  private function collectUnknownPropViolations(string $componentId, mixed $props, ?array $propSources, string $componentPath, ConstraintViolationList $violations): void {
    // @todo Remove once \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::clientModelToInput() records dropped props in its own violation list.
    if (!\is_array($props)) {
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the props must be a mapping of prop names to values.', $componentId),
        \sprintf('%s.props', $componentPath),
        $props,
      );
      return;
    }
    // Unknown props on block components are NOT validated here. Block inputs are
    // plugin settings, not JSON-Schema props, so blocks expose no propSources to
    // diff against. This matches Canvas core, which does not flag them either:
    // BlockComponent::clientModelToInput() silently drops unknown keys and
    // BlockComponent::validateComponentInput() never checks for them.
    // @todo Validate blocks too once BlockComponent::clientModelToInput() reports its dropped unknown keys via the $violations list it already receives.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::clientModelToInput()
    if ($propSources === NULL) {
      return;
    }
    // clientModelToInput() only reads resolved values for props the component
    // defines, so props that do not exist are silently dropped and would
    // otherwise pass validation.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::clientModelToInput()
    foreach (\array_diff_key($props, $propSources) as $propName => $propValue) {
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the `%s` prop is not defined.', $componentId, $propName),
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
      );
    }
  }

  /**
   * Collects violations for wrongly typed boolean, integer and number props.
   *
   * @todo Remove this workaround once #3592046 makes ComponentTreeItemList::validate() report these violations itself.
   *
   * @param string $componentId
   *   The component ID, used in violation messages.
   * @param array $props
   *   The AI-supplied props.
   * @param array|null $propSources
   *   The component's defined prop sources, or NULL for sources that are not
   *   prop-based (e.g. block components).
   * @param string $componentPath
   *   The component path, used to build the violation property path.
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add a violation to when a value fails.
   */
  private function collectPrimitiveTypeViolations(string $componentId, array $props, ?array $propSources, string $componentPath, ConstraintViolationList $violations): void {
    if ($propSources === NULL) {
      return;
    }
    foreach (\array_intersect_key($props, $propSources) as $propName => $propValue) {
      [$dataType, $expectation] = match ($propSources[$propName]['jsonSchema']['type'] ?? NULL) {
        'boolean' => ['boolean', 'expected a boolean (`true` or `false`)'],
        'integer' => ['integer', 'expected an integer'],
        'number' => ['float', 'expected a number'],
        default => [NULL, NULL],
      };
      if ($propValue === NULL || $dataType === NULL) {
        continue;
      }
      // Skip values that pass core's primitive type validation for this data type.
      if ($this->getTypedDataManager()->create(DataDefinition::create($dataType), $propValue)->validate()->count() === 0) {
        continue;
      }
      $this->addGarbageInputViolation(
        $violations,
        \sprintf('Component `%s`: the `%s` prop value %s cannot be stored: %s.', $componentId, $propName, \json_encode($propValue), $expectation),
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
      );
    }
  }

  /**
   * Adds a violation for a prop value that cannot be stored as given.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationList $violations
   *   The list to add the violation to.
   * @param string $message
   *   The violation message.
   * @param string $path
   *   The violation's property path.
   * @param mixed $value
   *   The offending value, for the violation's `getInvalidValue()`.
   */
  private function addGarbageInputViolation(ConstraintViolationList $violations, string $message, string $path, mixed $value): void {
    $violations->add(new ConstraintViolation(
      $message,
      NULL,
      [],
      NULL,
      $path,
      $value,
      code: ComponentTreeItem::VIOLATION_CODE_GARBAGE_INPUT,
    ));
  }

  /**
   * Builds the path translation map.
   *
   * @param array $componentTreeData
   *   The component tree data.
   * @param array $pathMapping
   *   The path mapping array.
   *
   * @return array
   *   The path translation map.
   */
  private function buildPathTranslationMap(array $componentTreeData, array $pathMapping): array {
    $pathMap = [];

    // Map field-level validation paths from ComponentTreeItemList->validate().
    foreach ($componentTreeData as $index => $component) {
      $uuid = $component['uuid'];
      if (isset($pathMapping[$uuid])) {
        $originalPath = $pathMapping[$uuid];

        // Map component field paths from field-level validation.
        // The actual violation paths are just numeric indices.
        $pathMap["{$index}.component_id"] = $originalPath;
        $pathMap["{$index}.uuid"] = $originalPath;
        $pathMap["{$index}.component_version"] = $originalPath;
        $pathMap["{$index}.parent_uuid"] = $originalPath;

        // For slot validation errors, point to the parent component.
        $pathMap["{$index}.slot"] = isset($component['parent_uuid'])
          ? $pathMapping[$component['parent_uuid']] ?? ''
          : $originalPath;

        // Map input validation paths from field-level validation.
        $pathMap["{$index}.inputs.{$uuid}."] = $originalPath . '.props.';
      }
    }

    return $pathMap;
  }

  /**
   * Renders collected tool errors as one markdown list per item.
   *
   * @param array<string, list<string>> $errors
   *   The errors found, keyed by the label of the item they belong to, for
   *   example "Operation 0".
   *
   * @return string
   *   The message reported to the model.
   */
  public function formatErrors(array $errors): string {
    $sections = ['Nothing was applied. Fix every error listed below and call the tool again.'];
    foreach ($errors as $item => $item_errors) {
      $bullets = \array_map(static fn (string $error): string => '- ' . $error, $item_errors);
      $sections[] = \sprintf("## %s\n%s", $item, \implode("\n", $bullets));
    }
    return \implode("\n\n", $sections);
  }

}
