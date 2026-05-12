<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Documents the current SDC limitation around content-entity-reference props.
 *
 * `JsComponent` keeps the developer-facing props abstract and projects
 * `x-allowed-entity-type-id` / `x-allowed-bundle` into the SDC definition only
 * at runtime via `JavaScriptComponent::toSdcDefinition()`. SDCs cannot do this:
 * they author the keys directly in `*.component.yml`, which currently fails
 * discovery.
 *
 * @todo Refine as part of https://www.drupal.org/i/3585135.
 *
 * @see docs/shape-matching.md
 * @see docs/adr/0011-content-entity-reference-props-in-code-components.md
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class SdcContentEntityReferencePropTest extends CanvasKernelTestBase {

  private const string COMPONENT_SOURCE_ID = 'canvas_test_sdc_content_entity_ref:contributor-card';

  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc_content_entity_ref',
  ];

  /**
   * An SDC with a content-entity-reference prop is currently ineligible.
   */
  public function testSdcContentEntityReferencePropIsNotYetSupported(): void {
    $component_id = SingleDirectoryComponentDiscovery::getComponentConfigEntityId(self::COMPONENT_SOURCE_ID);
    $reason_repository = $this->container->get(ComponentIncompatibilityReasonRepository::class);
    \assert($reason_repository instanceof ComponentIncompatibilityReasonRepository);

    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // No Component config entity is created — the SDC fails the requirements
    // checker because Canvas can't resolve a StorablePropShape for the
    // content-entity-reference prop authored in the SDC YAML.
    self::assertNull(ComponentEntity::load($component_id));

    $ineligible_reasons = $reason_repository->getReasons()[SingleDirectoryComponent::SOURCE_PLUGIN_ID] ?? [];
    self::assertArrayHasKey($component_id, $ineligible_reasons);
    self::assertSame(
      [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>contributor</code> prop, with the shape <code>{"type":"object","x-allowed-entity-type-id":"user"}</code>.',
      ],
      $ineligible_reasons[$component_id],
    );
  }

}
