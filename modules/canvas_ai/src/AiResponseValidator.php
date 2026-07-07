<?php

namespace Drupal\canvas_ai;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Component\Uuid\UuidInterface;
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
    // Props that do not exist on their component are silently dropped by
    // ComponentSourceInterface::clientModelToInput(), so field-level validation
    // below never sees them. Collect them during conversion instead.
    $unknownPropViolations = new ConstraintViolationList();

    // Convert YAML structure to Canvas ComponentTreeItem format.
    $componentTreeData = $this->convertToComponentTreeData($componentGroups, NULL, NULL, 'components', $pathMapping, $unknownPropViolations);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue($componentTreeData);
    $violations = $componentTreeItemList->validate();

    if ($violations->count() > 0 || $unknownPropViolations->count() > 0) {
      $translatedViolations = $this->translateConstraintPropertyPathsAndRoot(
        $this->buildPathTranslationMap($componentTreeData, $pathMapping),
        $violations,
        ''
      );
      // Unknown-prop violations are built with already-translated paths.
      $translatedViolations->addAll($unknownPropViolations);
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
   * @param \Symfony\Component\Validator\ConstraintViolationList $unknownPropViolations
   *   Collects violations for props that do not exist on their component.
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
    ConstraintViolationList $unknownPropViolations,
  ): array {
    $componentTreeData = [];
    foreach ($componentGroups as $groupIndex => $componentGroup) {
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
          $this->collectUnknownPropViolations($componentId, $componentData['props'], $propSources, $componentPath, $unknownPropViolations);
          if (\is_array($componentData['props'])) {
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
        if (isset($componentData['slots']) && \is_array($componentData['slots'])) {
          foreach ($componentData['slots'] as $slot => $slotComponentGroups) {
            $slotPath = \sprintf('%s.slots.%s', $componentPath, $slot);
            $componentTreeData = array_merge(
              $componentTreeData,
              $this->convertToComponentTreeData(
                $slotComponentGroups,
                $componentUuid,
                $slot,
                $slotPath,
                $pathMapping,
                $unknownPropViolations
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
      $violations->add(new ConstraintViolation(
        \sprintf('Component `%s`: the props must be a mapping of prop names to values.', $componentId),
        NULL,
        [],
        NULL,
        \sprintf('%s.props', $componentPath),
        $props,
        code: ComponentTreeItem::VIOLATION_CODE_GARBAGE_INPUT,
      ));
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
      $violations->add(new ConstraintViolation(
        \sprintf('Component `%s`: the `%s` prop is not defined.', $componentId, $propName),
        NULL,
        [],
        NULL,
        \sprintf('%s.props.%s', $componentPath, $propName),
        $propValue,
        code: ComponentTreeItem::VIOLATION_CODE_GARBAGE_INPUT,
      ));
    }
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

}
