<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\TestSite;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

class XBTestSetup implements TestSetupInterface {

  public function setup(): void {
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.logging');
    $config->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE);
    $config->save(TRUE);
    $module_installer = \Drupal::service('module_installer');
    $module_installer->install(['node']);

    $type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $type->save();

    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install([
      'experience_builder',
      // Modules providing field types + widgets used by XB's default config.
      'image',
    ]);

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
    ]);
    $node->save();

    $xb_role = Role::create([
      'id' => 'xb',
      'label' => 'xb',
    ]);
    $xb_role->grantPermission('access administration pages');
    $xb_role->grantPermission('access content');

    $xb_role->save();

    $xb_user = User::create();
    $xb_user->setUsername('xbUser');
    $xb_user->setPassword('xbUser');
    $xb_user->setEmail('xb@test.com');
    $xb_user->addRole((string) $xb_role->id());
    $xb_user->enforceIsNew();
    $xb_user->activate();
    $xb_user->save();
  }

}
