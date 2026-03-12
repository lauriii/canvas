<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Architecture;

use Drupal\canvas\PropExpressions\PropExpressionInterface;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class PropExpressions {

  #[TestRule]
  public function areStandalone(): Rule {
    return PHPat::rule()
      ->classes(Selector::inNamespace('Drupal\canvas\PropExpressions'))
      ->canOnlyDependOn()
      ->classes(
        // Can only depend on other classes in the same namespace.
        Selector::inNamespace('Drupal\canvas\PropExpressions'),
        // Plus Drupal core components.
        Selector::inNamespace('Drupal\Component'),
        // Plus specific Drupal core namespaces.
        Selector::inNamespace('Drupal\Core\Access'),
        Selector::inNamespace('Drupal\Core\Cache'),
        Selector::inNamespace('Drupal\Core\Entity'),
        Selector::inNamespace('Drupal\Core\Field'),
        Selector::inNamespace('Drupal\Core\Http\Exception'),
        Selector::inNamespace('Drupal\Core\TypedData'),
        Selector::inNamespace('Drupal\Core\StringTranslation'),
        // For the Labeler & Evaluator to get the container.
        Selector::classname(\Drupal::class),
        // Special case in the Evaluator: datetime fields.
        // @todo Remove this in https://www.drupal.org/project/canvas/issues/3573934
        Selector::inNamespace('Drupal\datetime\Plugin\Field\FieldType'),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
        // With one exception: a Canvas-provided fix for broken core infra.
        // @see https://www.drupal.org/project/drupal/issues/2169813
        Selector::classname(BetterEntityDataDefinition::class),
      )
      ->because('The entire PropExpressions infrastructure should remain stand-alone because it may be relevant to eventually move to Drupal core. See https://www.drupal.org/project/drupal/issues/2002254#comment-16459017.');
  }

  #[TestRule]
  public function haveFinalImplementations(): Rule {
    return PHPat::rule()
      ->classes(Selector::implements(PropExpressionInterface::class))
      ->excluding(Selector::isInterface())
      ->shouldBeFinal()
      ->because('Every concrete prop expression class must be final, to avoid unintended inheritance and to make it easier to refactor and change the class hierarchy in the future without worrying about breaking custom implementations.');
  }

}
