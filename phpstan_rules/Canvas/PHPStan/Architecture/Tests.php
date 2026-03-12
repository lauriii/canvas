<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class Tests {

  #[TestRule]
  public function mustHaveGroups(): Rule {
    return PHPat::rule()
      ->classes(Selector::extends(TestCase::class))
      // Exclude abstract test base classes.
      ->excluding(Selector::isAbstract())
      ->shouldApplyAttribute()
      ->classes(Selector::classname(Group::class))
      ->because('All test classes must declare a #[Group] attribute. Preferably multiple: once `#[Group(\'canvas\')]`, once #[Group[\'canvas_SOMETHING\')].');
  }

}
