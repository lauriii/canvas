<?php

declare(strict_types=1);

namespace Drupal\canvas_page_template_component;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Blocks uninstalling while a page variant uses a theme page template.
 *
 * The theme page template components provided by this module render a theme's
 * page-level and region-level markup. While any `page_variant` config entity
 * places one of these components in its tree, uninstalling would remove the
 * source and turn that component into the fallback, changing the rendered page.
 * The site owner must first remove the component from every variant.
 *
 * @see \Drupal\canvas\ComponentDependencyUninstallValidator
 * @see \Drupal\canvas_page_template_component\Plugin\Canvas\ComponentSource\ThemePageTemplate
 */
final class PageTemplateComponentUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ComponentAudit $componentAudit,
    private readonly ConfigManagerInterface $configManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'canvas_page_template_component') {
      return [];
    }

    $component_definition = $this->entityTypeManager->getDefinition(Component::ENTITY_TYPE_ID);
    \assert($component_definition instanceof ConfigEntityTypeInterface);
    $dependencies = $this->configManager->findConfigEntityDependencies('module', [$module]);
    $components = \array_filter($dependencies, static fn (ConfigEntityDependency $dependency): bool => \str_starts_with($dependency->getConfigDependencyName(), $component_definition->getConfigPrefix()));
    if (\count($components) === 0) {
      return [];
    }
    $components = \array_map(fn (ConfigEntityDependency $dependency) => $this->configManager->loadConfigEntityByName($dependency->getConfigDependencyName()), $components);

    $reasons = [];
    foreach ($components as $component) {
      \assert($component instanceof ComponentInterface);
      $usage = $this->componentAudit->getConfigEntityDependenciesUsingAuditTarget($component, PageVariant::ENTITY_TYPE_ID);
      $count = \count($usage);
      if ($count === 0) {
        continue;
      }
      $reasons[] = new PluralTranslatableMarkup(
        $count,
        'The %component component is used in 1 page variant. Remove it from that variant first.',
        'The %component component is used in @count page variants. Remove it from those variants first.',
        [
          '%component' => $component->label(),
        ],
      );
    }
    // @phpstan-ignore-next-line
    return $reasons;
  }

}
