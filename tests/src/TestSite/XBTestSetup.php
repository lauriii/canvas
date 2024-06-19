<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\TestSite;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

class XBTestSetup implements TestSetupInterface {

  public function setup(): void {
    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['experience_builder']);

    $xb_role = Role::create([
      'id' => 'xb',
      'label' => 'xb',
    ]);
    $xb_role->grantPermission('access administration pages');
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
