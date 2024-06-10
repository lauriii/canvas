<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Entity;

use Drupal\experience_builder\Entity\Component;
use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;

/**
 * Tests validation of component entities.
 *
 * @group experience_builder
 */
class ComponentValidationTest extends ConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'experience_builder',
    'sdc',
    'sdc_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = Component::create([
      'component' => 'sdc_test+my-cta',
      'label' => 'Test',
    ]);
    $this->entity->save();
  }

  /**
   * Data provider for ::testInvalidMachineNameCharacters().
   *
   * @return array<string, array<int, bool|string>>
   *   The test cases.
   */
  public static function providerInvalidMachineNameCharacters(): array {
    return [
      'INVALID: space separated' => ['space separated+space separated', FALSE],
      'INVALID: uppercase letters' => ['Uppercase_Letters+Uppercase_Letters', FALSE],
      'INVALID: period separated' => ['period.separated+period.separated]', FALSE],
      'INVALID: only underscore separated' => ['underscore_separated_underscore_separated', FALSE],
      'VALID: plus instead of colon' => ['provider+component', TRUE],
      'VALID: dash separated' => ['dash-separated+dash-separated', TRUE],
      'VALID: underscore separated' => ['underscore_separated+underscore_separated', TRUE],
    ];
  }

  /**
   * Machine name of \Drupal\experience_builder\Entity\Component needs to be joined with +.
   * @param $length
   *
   * @return string
   */
  protected function randomMachineName($length = 8) {
    return parent::randomMachineName(intdiv($length, 2)) . '+' . parent::randomMachineName(intdiv($length, 2));
  }

  /**
   * Tests validating a component with a SDC machine name.
   */
  public function testInvalidComponent(): void {
    $this->entity->set('component', Component::convertIdToMachineName($this->entity->get('component')));
    $this->assertValidationErrors([
      '' => "The 'component' property cannot be changed.",
      'component' => 'The <em class="placeholder">&quot;sdc_test:my-cta&quot;</em> machine name is not valid.',
    ]);
  }

}
