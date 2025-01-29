<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\Tests\migrate_drupal\Traits\CreateTestContentEntitiesTrait;
use Symfony\Component\Yaml\Yaml;

trait CreateTestJsComponentTrait {

  private function createMyCtaComponentFromSdc(): void {
    // Create a "code component" that has the same explicit inputs as the
    // `sdc_test:my-cta`.
    $sdc_yaml = Yaml::parseFile('core/modules/system/tests/modules/sdc_test/components/my-cta/my-cta.component.yml');
    $props = array_diff_key(
      $sdc_yaml['props']['properties'],
      // SDC has special infrastructure for a prop named "attributes".
      array_flip(['attributes']),
    );
    // The `sdc_test:my-cta` SDC does not actually meet the requirements.
    $props['href']['examples'][] = 'https://example.com';
    $props['target']['examples'][] = '_blank';
    // TRICKY: for a <select>, the empty string is not a valid choice; it's the
    // equivalent of *not* choosing a value.
    assert($props['target']['enum'][0] === '');
    $props['target']['enum'][0] = '_self';
    JavaScriptComponent::create([
      'machineName' => 'my-cta',
      'name' => 'My First Code Component',
      'status' => TRUE,
      'props' => $props,
      'required' => $sdc_yaml['props']['required'],
      'source_code_js' => '',
      'source_code_css' => '',
      'compiled_js' => '',
      'compiled_css' => '',
    ])->save();
  }
}
