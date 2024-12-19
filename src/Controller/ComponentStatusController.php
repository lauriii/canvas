<?php

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Not every unavailable/disabled SDC will have Component entity, so we're using a controller instead of EntityListBuilder for this.
 *
 * @see \Drupal\experience_builder\Plugin\ComponentPluginManager::setCachedDefinitions()
 *
 * @todo Ensure reasons are translated.
 * @todo Handle non SDC components, see https://www.drupal.org/project/experience_builder/issues/3484672
 */
final class ComponentStatusController {

  use StringTranslationTrait;

  /**
   * @param \Drupal\experience_builder\Plugin\ComponentPluginManager $componentPluginManager
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly StateInterface $state,
    private readonly MessengerInterface $messenger,
  ) {}

  public function __invoke(): array {
    // @todo State API is not guaranteed to stay in sync with SDC discovery cache and we should revisit this and choose more reliable, but still performant storage.
    // @see https://www.drupal.org/node/3177901
    $this->componentPluginManager->clearCachedDefinitions();
    $this->componentPluginManager->getDefinitions();

    $reasons = $this->state->get(ComponentPluginManager::REASONS_STATE_KEY);
    $rows = [];
    $header = [
      [
        'data' => $this->t('Component'),
      ],
      [
        'data' => $this->t('Status'),
      ],
      [
        'data' => $this->t('Reason'),
      ],
    ];
    foreach ($reasons as $component => $reason) {
      $component_entity = Component::load(SingleDirectoryComponent::convertMachineNameToId($component));
      $status = $component_entity instanceof Component && !$component_entity->status() ? $this->t('Disabled') : $this->t('Incompatible');

      $rows[] = [
        'data' => [
          $component,
          $status,
          Markup::create($reason),
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No incompatible components detected.'),
    ];
  }

  /**
   * Calls a method on a component and reloads the listing page.
   *
   * @param \Drupal\experience_builder\Entity\Component $component
   *   The component being acted upon.
   * @param string $op
   *   The operation to perform, e.g., 'enable' or 'disable'.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect back to the listing page.
   */
  public function performOperation(Component $component, string $op) {
    assert(in_array($op, ['enable', 'disable']));

    $reasons = $this->state->get(ComponentPluginManager::REASONS_STATE_KEY);
    if ($op === 'disable') {
      $component->disable()->save();
      $reasons[$component->getComponentPluginId()] = 'Manually disabled';
    }
    elseif ($op === 'enable') {
      $component_plugin = $this->componentPluginManager->getDefinition($component->getComponentPluginId());
      if ($this->componentPluginManager->componentMeetsRequirements($component_plugin)) {
        $component->enable()->save();
        unset($reasons[$component->getComponentPluginId()]);
      }
      else {
        $this->messenger->addError($this->t('The component %component does not meet requirements: %reason', [
          "%component" => $component->id(),
          "%reason" => $reasons[$component->getComponentPluginId()],
        ]));
        return new RedirectResponse(Url::fromRoute('entity.component.collection')->toString());
      }
    }
    $this->state->set(ComponentPluginManager::REASONS_STATE_KEY, $reasons);

    $this->messenger->addStatus($this->t('The component %component has been updated', [
      "%component" => $component->id(),
    ]));
    return new RedirectResponse(Url::fromRoute('entity.component.collection')->toString());
  }

}
