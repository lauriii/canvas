<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Architecture;

use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\canvas\PropExpressions\PropExpressionInterface;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\MimeType\ExtensionMimeTypeGuesser;
use Drupal\Core\ProxyClass\File\MimeType\ExtensionMimeTypeGuesser as LazyExtensionMimeTypeGuesser;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;
use Drupal\options\Plugin\Field\FieldType\ListFloatItem;
use Drupal\options\Plugin\Field\FieldType\ListIntegerItem;
use Drupal\telephone\Plugin\Field\FieldType\TelephoneItem;
use Drupal\text\TextProcessed;
use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class Layers {

  #[TestRule]
  public function propExpressionsAreStandAlone(): Rule {
    return PHPat::rule()
      ->classes(Selector::inNamespace('Drupal\canvas\PropExpressions'))
      ->canOnlyDependOn()
      ->classes(
        // Can only depend on other classes in the same namespace.
        Selector::inNamespace('Drupal\canvas\PropExpressions'),
        // Plus Canvas' Typed Data helper, which could be moved into core.
        Selector::inNamespace('Drupal\canvas\Utility'),
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
  public function propExpressionsHaveFinalImplementations(): Rule {
    return PHPat::rule()
      ->classes(Selector::implements(PropExpressionInterface::class))
      ->excluding(Selector::isInterface())
      ->shouldBeFinal()
      ->because('Every concrete prop expression class must be final, to avoid unintended inheritance and to make it easier to refactor and change the class hierarchy in the future without worrying about breaking custom implementations.');
  }

  #[TestRule]
  public function propSources(): Rule {
    return PHPat::rule()
      ->classes(Selector::inNamespace('Drupal\canvas\PropSource'))
      ->canOnlyDependOn()
      ->classes(
        // Can only depend on other classes in the same namespace.
        Selector::inNamespace('Drupal\canvas\PropSource'),
        // And builds on top of Canvas' PropExpressions + PropShape.
        Selector::inNamespace('Drupal\canvas\PropExpressions'),
        Selector::inNamespace('Drupal\canvas\PropShape'),
        // AdaptedPropSource needs adapter plugin infrastructure.
        Selector::classname(AdapterInterface::class),
        Selector::classname(AdapterManager::class),
        // DefaultRelativeUrlPropSource needs the UrlRewriteInterface,
        // JsonSchemaStringFormat and a Component config entity.
        Selector::classname(UrlRewriteInterface::class),
        Selector::classname(JsonSchemaStringFormat::class),
        Selector::classname(ConfigEntityTypeInterface::class),
        Selector::classname(Component::class),
        // EntityFieldPropSource and HostEntityUrlPropSource need a host entity.
        Selector::classname(MissingHostEntityException::class),
        // Plus Drupal core components.
        Selector::inNamespace('Drupal\Component'),
        // Plus specific Drupal core namespaces.
        Selector::inNamespace('Drupal\Core\Cache'),
        Selector::inNamespace('Drupal\Core\Entity'),
        Selector::inNamespace('Drupal\Core\Field'),
        Selector::inNamespace('Drupal\Core\TypedData'),
        Selector::inNamespace('Drupal\Core\StringTranslation'),
        // Some prop sources need services: Typed Data manager, entity type
        // manager, adapter plugin manager …
        Selector::classname(\Drupal::class),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
        // @todo Remove these when \Drupal\canvas\PropSource\StaticPropSource::formTemporaryRemoveThisExclamationExclamationExclamation is removed.
        Selector::classname(DrupalDateTime::class),
        Selector::inNamespace('Drupal\Core\Form'),
      )
      ->because("The entire PropSource infrastructure should depend only on core + Canvas' PropExpressions + PropShape + adapters + select classes.");
  }

  #[TestRule]
  public function shapeMatcher(): Rule {
    return PHPat::rule()
      ->classes(Selector::inNamespace('Drupal\canvas\ShapeMatcher'))
      ->canOnlyDependOn()
      ->classes(
        // Can only depend on other classes in the same namespace.
        Selector::inNamespace('Drupal\canvas\ShapeMatcher'),
        // And builds on top of Canvas' PropExpressions + PropShape + PropSource
        // + JsonSchemaInterpreter + adapter.
        Selector::inNamespace('Drupal\canvas\PropExpressions'),
        Selector::inNamespace('Drupal\canvas\PropShape'),
        Selector::inNamespace('Drupal\canvas\PropSource'),
        Selector::inNamespace('Drupal\canvas\JsonSchemaInterpreter'),
        Selector::classname(AdapterInterface::class),
        Selector::classname(AdapterManager::class),
        // Shape matching only exists for
        // GeneratedFieldExplicitInputUxComponentSourceBase.
        Selector::classname(GeneratedFieldExplicitInputUxComponentSourceBase::class),
        // Shape matching is powered by validation infrastructure.
        Selector::inNamespace('Symfony\Component\Validator'),
        Selector::inNamespace('Drupal\Core\Validation'),
        Selector::inNamespace('Drupal\canvas\Plugin\Validation'),
        // Plus Drupal core components.
        Selector::inNamespace('Drupal\Component'),
        // Plus specific Drupal core namespaces.
        Selector::inNamespace('Drupal\Core\Cache'),
        Selector::inNamespace('Drupal\Core\Entity'),
        Selector::inNamespace('Drupal\Core\Field'),
        Selector::inNamespace('Drupal\Core\TypedData'),
        // Plus one specific class for SDC metadata.
        Selector::classname(ComponentMetadata::class),
        // Plus specific classes for core field types needing special care.
        Selector::classname(FileItem::class),
        Selector::classname(FileUriItem::class),
        Selector::classname(ListFloatItem::class),
        Selector::classname(ListIntegerItem::class),
        Selector::classname(TelephoneItem::class),
        Selector::classname(TextProcessed::class),
        // Plus specific classes for the most complex case: files.
        Selector::classname(ExtensionMimeTypeGuesser::class),
        Selector::classname(LazyExtensionMimeTypeGuesser::class),
        // For resolving schema references, a service is needed from the
        // container.
        Selector::inNamespace('Symfony\Component\DependencyInjection'),
        // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::resolveSchemaReferences()
        Selector::classname(\Drupal::class),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
        // With one exception: a Canvas-provided fix for broken core infra.
        // @see https://www.drupal.org/project/drupal/issues/2169813
        Selector::classname(BetterEntityDataDefinition::class),
        // @todo Remove in https://www.drupal.org/project/canvas/issues/3552818
        Selector::classname(ComponentPluginManager::class)
      )
      ->because("The entire ShapeMatcher infrastructure should depend only on core + Canvas' PropExpressions + PropShape + PropSource + JSON schema interpreter + adapters + select classes.");
  }

}
