<?php

declare(strict_types=1);

namespace Drupal\xb_test_block\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Defines hooks for xb_test_block module.
 */
final class XbTestBlockHooks {

  public function __construct(
    #[Autowire(service: 'keyvalue')]
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {
  }

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    // @see \Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource\BlockComponentTest::testVersionDeterminability()
    if ($this->keyValueFactory->get('xb_test_block')->get('i_can_haz_alter?') !== NULL) {
      $definitions['block.settings.xb_test_block_input_validatable']['mapping']['name']['constraints']['NotEqualTo'] = [
        'value' => 'Not XB',
        'message' => 'This is only for XB, get out of here!',
      ];
    }
  }

}
