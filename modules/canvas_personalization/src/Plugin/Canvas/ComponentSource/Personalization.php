<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentSource\ComponentSourceBase;
use Drupal\canvas\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\canvas\ComponentSource\ComponentSourceWithSwitchCasesInterface;
use Drupal\canvas\ComponentSource\SwitchCaseNegotiation;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\MissingComponentInputsException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\SegmentEvaluator;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Plugin\Validation\Constraint\ConfigExistsConstraint;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Personalization component source providing switch/case components.
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @phpstan-type PersonalizationSwitchInputArray array{variants: array<int, string>}
 * @phpstan-type PersonalizationCaseInputArray array{variant_id: string, segments: array<int, string>, disabled?: bool}
 * @phpstan-type PersonalizationInputArray PersonalizationSwitchInputArray|PersonalizationCaseInputArray
 *
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Personalization'),
  supportsImplicitInputs: FALSE,
  discovery: FALSE,
)]
final class Personalization extends ComponentSourceBase implements
  ComponentSourceWithSlotsInterface,
  ComponentSourceWithSwitchCasesInterface,
  ContainerFactoryPluginInterface {

  use ConstraintPropertyPathTranslatorTrait;

  public const string SOURCE_PLUGIN_ID = 'p13n';

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly BasicRecursiveValidatorFactory $validatorFactory,
    private readonly SegmentEvaluator $segmentEvaluator,
  ) {
    \assert(\array_key_exists('local_source_id', $configuration));
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(BasicRecursiveValidatorFactory::class),
      $container->get(SegmentEvaluator::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isBroken(): bool {
    // The two components provided by this ComponentSource are hard-coded.
    return FALSE;
  }

  public function getReferencedPluginClass(): ?string {
    return NULL;
  }

  public function getComponentDescription(): TranslatableMarkup {
    return match ($this->getType()) {
      self::SWITCH => new TranslatableMarkup('Personalization'),
      self::CASE => new TranslatableMarkup('Variant'),
    };
  }

  /**
   * @return 'switch'|'case'
   */
  protected function getType(): string {
    return $this->configuration['local_source_id'];
  }

  public function isSwitch(): bool {
    return $this->getType() === self::SWITCH;
  }

  public function isCase():bool {
    return $this->getType() === self::CASE;
  }

  public function renderComponent(array $inputs, array $slot_definitions, string $componentUuid, bool $isPreview): array {
    $build = [];

    // When live rendering, a switch is never visible to the end user: zero
    // markup. Note this has no cacheability (beyond the render system's
    // default), because this renders to nothing. The cacheability of the
    // negotiation is attached to the switch's render element by
    // ComponentTreeItemList::renderify(), which also prunes non-negotiated
    // cases — so a case that is rendered at all just renders its container.
    if (!$isPreview && $this->isSwitch()) {
      return $build;
    }

    // We do render container markup for:
    // - the one negotiated `case` when live rendering
    // - the `switch` and ALL `case`s when previewing
    $build += [
      '#type' => 'container',
      '#attributes' => [
        'canvas_uuid' => $componentUuid,
        'canvas_type' => $this->getType(),
        'canvas_slot_ids' => \array_keys($slot_definitions),
      ],
    ];
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Walks the switch's `variants` input in priority order; a variant matches
   * when ALL segments referenced by its case match the current request; the
   * first match wins, disabled cases are skipped. The returned cacheability
   * covers every segment referenced by any case — the match decision
   * short-circuits, the metadata collection never does, because a cached
   * response must be correct for request contexts in which an
   * earlier-priority variant matches.
   */
  public function negotiateCases(array $switch_instance): SwitchCaseNegotiation {
    if (!$this->isSwitch()) {
      throw new \LogicException('Only switches negotiate cases.');
    }

    // Index the hydrated cases by variant ID.
    $cases = [];
    foreach ($switch_instance['slots'] ?? [] as $slot_children) {
      if (!\is_array($slot_children)) {
        continue;
      }
      foreach ($slot_children as $case_uuid => $case) {
        // A case whose hydration failed has no inputs; treat it as absent.
        if (!\is_array($case) || !\is_string($case['variant_id'] ?? NULL)) {
          continue;
        }
        $cases[$case['variant_id']] = [
          'uuid' => (string) $case_uuid,
          'segments' => $case['segments'] ?? [],
          'disabled' => (bool) ($case['disabled'] ?? FALSE),
        ];
      }
    }

    $cacheability = new CacheableMetadata();
    foreach ($cases as $case) {
      foreach ($case['segments'] as $segment_id) {
        $cacheability = $cacheability->merge($this->segmentEvaluator->evaluate($segment_id)->cacheability);
      }
    }

    $negotiated_case_uuid = NULL;
    foreach ($switch_instance['variants'] ?? [] as $variant_id) {
      $case = $cases[$variant_id] ?? NULL;
      if ($case === NULL || $case['disabled'] || $case['segments'] === []) {
        continue;
      }
      $all_match = TRUE;
      foreach ($case['segments'] as $segment_id) {
        if (!$this->segmentEvaluator->evaluate($segment_id)->matched) {
          $all_match = FALSE;
          break;
        }
      }
      if ($all_match) {
        $negotiated_case_uuid = $case['uuid'];
        break;
      }
    }

    return new SwitchCaseNegotiation($negotiated_case_uuid, $cacheability);
  }

  public function requiresExplicitInput(): bool {
    // - `switch` requires variant IDs
    // - `case` requires variant ID + segment IDs
    return TRUE;
  }

  public function getExplicitInput(string $uuid, ComponentTreeItem $item, ?FieldableEntityInterface $host_entity = NULL): array {
    try {
      // Inputs might be NULL, so ensure we return a valid array.
      return $item->getInputs() ?? $this->getDefaultExplicitInput();
    }
    catch (MissingComponentInputsException) {
      return $this->getDefaultExplicitInput();
    }
  }

  public function hydrateComponent(array $explicit_input, array $slot_definitions, array $active_required_explicit_inputs): array {
    $hydrated = $explicit_input;
    // Set the slots.
    if (!empty($slot_definitions)) {
      // Use the first example defined in the components metadata, which we
      // guarantee it exists.
      $hydrated['slots'] = \array_map(fn($slot) => $slot['examples'][0], $slot_definitions);
    }
    return $hydrated;
  }

  public function inputToClientModel(array $explicit_input): array {
    // @see DynamicComponent type-script definition.
    // @see ComponentModel type-script definition.
    return ['resolved' => $explicit_input];
  }

  public function getClientSideInfo(Component $component): array {
    // These components are hidden from the component library (the variants
    // menu is the authoring surface), but the client still needs their slot
    // metadata and version strings to build switch/case instances.
    return [
      'build' => [],
      'metadata' => [
        'slots' => $this->getSlotDefinitions(),
      ],
    ];
  }

  public function clientModelToInput(string $component_instance_uuid, Component $component, array $client_model, ?FieldableEntityInterface $host_entity, ?ConstraintViolationListInterface $violations = NULL): array {
    return $client_model['resolved'] ?? [];
  }

  public function validateComponentInput(array $inputValues, string $component_instance_uuid, ?FieldableEntityInterface $entity): ConstraintViolationListInterface {
    $variant_id_constraints = new Sequentially([
      new Type('string'),
      new NotBlank(),
      // @see `type: machine_name`
      new Regex(pattern: '/^[a-z0-9_]+$/'),
    ]);
    $segment_id_constraints = new Sequentially([
      new Type('string'),
      new NotBlank(),
      new ConfigExistsConstraint(['prefix' => \sprintf('canvas_personalization.%s.', Segment::ENTITY_TYPE_ID)]),
    ]);

    $component_constraints = match ($this->getType()) {
      self::SWITCH => new Collection(
        fields: [
          'variants' => new Required([
            new Type('array'),
            new NotBlank(),
            new All([$variant_id_constraints]),
          ]),
        ],
        allowExtraFields: FALSE,
      ),
      self::CASE => new Collection(
        fields: [
          'variant_id' => new Required([$variant_id_constraints]),
          'segments' => new Required([
            new Type('array'),
            new NotBlank(),
            new All([$segment_id_constraints]),
          ]),
          'disabled' => new Optional([new Type('boolean')]),
        ],
        allowExtraFields: FALSE,
      ),
    };

    $non_typed_data_validator = $this->validatorFactory->createValidator();
    $violations = $non_typed_data_validator->validate($inputValues, $component_constraints);
    return $this->translateConstraintPropertyPathsAndRoot(['' => \sprintf('inputs.%s.', $component_instance_uuid)], $violations);
  }

  public function checkRequirements(): void {
    // Do nothing, our components are not dynamic and provided as module config.
  }

  public function calculateDependencies(): array {
    // Because our components have no settings, there also cannot be any
    // additional dependencies for their corresponding Component config
    // entities.
    return [
      'module' => [
        'canvas_personalization',
      ],
    ];
  }

  /**
   * @return PersonalizationInputArray
   * @phpstan-ignore-next-line method.childReturnType
   */
  public function getDefaultExplicitInput(bool $only_required = FALSE): array {
    return match($this->getType()) {
      self::SWITCH => [
        'variants' => [Segment::DEFAULT_ID],
      ],
      self::CASE => [
        'variant_id' => Segment::DEFAULT_ID,
        'segments' => [Segment::DEFAULT_ID],
      ],
    };
  }

  /**
   * {@inheritdoc}
   *
   * @todo Before offering this functionality to end users, this should switch to returning a declarative representation of the schema based on the validation constraints defined in ::validateComponentInput(). This only used JSON Schema as an MVP (inspired by JsComponent::getExplicitInputDefinitions()).
   */
  protected function getExplicitInputDefinitions(): array {
    return match($this->getType()) {
      self::SWITCH => [
        'required' => ['variants'],
        'shapes' => [
          'variants' => [
            'type' => 'array',
            'minItems' => 1,
            'items' => ['type' => 'string'],
          ],
        ],
      ],
      self::CASE => [
        'required' => TRUE,
        'variant_id' => ['type' => 'string'],
        'segments' => [
          'type' => 'array',
          'minItems' => 1,
          'items' => ['type' => 'string'],
        ],
        'disabled' => ['type' => 'boolean'],
      ],
    };
  }

  public function buildComponentInstanceForm(
    array $form,
    FormStateInterface $form_state,
    Component $component,
    string $component_instance_uuid = '',
    array $inputValues = [],
    ?EntityInterface $entity = NULL,
    array $settings = [],
  ): array {
    // These components have no author-editable settings of their own: the
    // variants menu and the segments dashboard are the authoring surfaces.
    // Render a short pointer instead of a form.
    return [
      'description' => [
        '#markup' => '<p>' . match ($this->getType()) {
          self::SWITCH => $this->t('This section is personalized. Use the variants menu in the toolbar to manage its variants.'),
          self::CASE => $this->t('This is one variant of a personalized section. Use the variants menu in the toolbar to choose which variant you are editing, reorder variants, or change their audience.'),
        } . '</p>',
      ],
    ];
  }

  public function getSlotDefinitions(): array {
    return [
      'content' => [
        'title' => 'Content',
        'description' => match ($this->getType()) {
          'switch' => 'The variants',
          'case' => 'The component tree for this variant',
        },
        'examples' => [
          '',
        ],
      ],
    ];
  }

  public function setSlots(array &$build, array $slots): void {
    // @see ::getSlotDefinitions()
    // A live-rendered switch whose negotiation matched no case has had every
    // case pruned, leaving nothing to set.
    \assert($slots === [] || \array_keys($slots) === ['content']);
    $build += $slots;
  }

}
