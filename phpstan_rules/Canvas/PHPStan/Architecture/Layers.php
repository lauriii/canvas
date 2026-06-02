<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Architecture;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\ComponentSource\UrlRewriteInterface;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas\InvalidComponentInputsPropSourceException;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\Plugin\AdapterManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentDiscoveryBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceUpdater;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\PropExpressionInterface;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\File\MimeType\ExtensionMimeTypeGuesser;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\ProxyClass\File\MimeType\ExtensionMimeTypeGuesser as LazyExtensionMimeTypeGuesser;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\Core\Url;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;
use Drupal\options\Plugin\Field\FieldType\ListFloatItem;
use Drupal\options\Plugin\Field\FieldType\ListIntegerItem;
use Drupal\telephone\Plugin\Field\FieldType\TelephoneItem;
use Drupal\text\TextProcessed;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

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
        // JsonSchemaPropsComponentSourceBase.
        Selector::classname(JsonSchemaPropsComponentSourceBase::class),
        // Shape matching is powered by validation infrastructure.
        self::usesConstraintViolationValueObjects(),
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

  #[TestRule]
  public function jsonSchemaPropsComponentSourceBaseDependsMostlyOnSupportingInfrastructure(): Rule {
    return PHPat::rule()
      ->classes(
        // The ComponentSource plugin.
        Selector::classname(JsonSchemaPropsComponentSourceBase::class),
        // Its handlers.
        Selector::classname(JsonSchemaPropsComponentDiscoveryBase::class),
        Selector::classname(JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class),
      )
      ->canOnlyDependOn()
      ->classes(
        // E.g. the discovery class can call public static methods on the main
        // plugin implementation.
        Selector::classname(JsonSchemaPropsComponentSourceBase::class),
        // Canvas component source base classes and interfaces.
        Selector::inNamespace('Drupal\canvas\ComponentSource'),
        self::depsOfComponentSourceInterfaceImplementations(),
        // Component Source-specific exceptions.
        Selector::classname(InvalidComponentInputsPropSourceException::class),
        Selector::classname(MissingHostEntityException::class),
        // This Component Source base class has a range of supporting infra.
        self::supportingInfraForJsonSchemaPropsComponentSourceBase(),
        // Core infrastructure to pass around JSON schema definitions for props,
        // and validating against those.
        Selector::classname(InvalidComponentException::class),
        Selector::inNamespace('Drupal\Core\Theme\Component'),
        // For the auto-generated input UX.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
        Selector::classname(FormStateInterface::class),
        Selector::classname(WidgetPluginManager::class),
        Selector::classname(ContentTemplate::class),
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::validateComponentInput()
        self::usesConstraintViolationValueObjects(),
        // For the translatability of inputs.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator
        Selector::classname(JsonSchemaType::class),
        Selector::classname(JsonSchemaStringFormat::class),
        Selector::classname(CanvasStaticPropSourceFieldWidget::class),
        // Canvas utilities.
        Selector::inNamespace('Drupal\canvas\Utility'),
        // Plus specific Drupal core namespaces.
        // NOTE: no Typed Data here, thanks to the supporting infra.
        // @see ::supportingInfraForJsonSchemaPropsComponentSourceBase()
        Selector::inNamespace('Drupal\Component\Assertion'),
        Selector::inNamespace('Drupal\Component\Utility'),
        Selector::inNamespace('Drupal\Core\Cache'),
        Selector::classname(EntityAdapter::class),
        Selector::inNamespace('Drupal\Core\Http\Exception'),
        Selector::inNamespace('Drupal\Core\Logger'),
        Selector::inNamespace('Drupal\Core\Plugin'),
        Selector::inNamespace('Drupal\Core\Render'),
        Selector::inNamespace('Drupal\Core\StringTranslation'),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
      )
      ->because('JsonSchemaPropsComponentSourceBase is the shared base class for all component source plugins without a native input UX, but that instead describe the shape of their inputs using JSON Schema; it should not depend on subclass-specific concerns.');
  }

  #[TestRule]
  public function singleDirectoryComponentSourceMaximallyLeveragesBaseInfra(): Rule {
    return PHPat::rule()
      ->classes(
        Selector::classname(SingleDirectoryComponent::class),
      )
      ->canOnlyDependOn()
      ->classes(
        Selector::classname(SingleDirectoryComponentDiscovery::class),
        // Canvas component source base classes and interfaces.
        Selector::inNamespace('Drupal\canvas\ComponentSource'),
        self::depsOfComponentSourceInterfaceImplementations(),
        // The base implementations.
        Selector::classname(JsonSchemaPropsComponentSourceBase::class),
        Selector::classname(JsonSchemaPropsComponentDiscoveryBase::class),
        Selector::classname(JsonSchemaPropsComponentInstanceUpdater::class),
        Selector::classname(JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class),
        // The SDC subsystem.
        Selector::inNamespace('Drupal\Core\Render\Component'),
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent::rewriteExampleUrl()
        Selector::classname(GeneratedUrl::class),
        Selector::classname(Url::class),
        // Drupal core namespaces.
        Selector::inNamespace('Drupal\Core\Extension'),
        Selector::inNamespace('Drupal\Core\Theme'),
        Selector::inNamespace('Drupal\Core\StringTranslation'),
        // Symfony dependencies.
        Selector::inNamespace('Symfony\Component\Filesystem'),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
      )
      ->because('SingleDirectoryComponent is largely powered by JsonSchemaPropsComponentSourceBase and the additional dependencies are only for SDC-specific logic.');
  }

  #[TestRule]
  public function jsComponentSourceMaximallyLeveragesBaseInfra(): Rule {
    return PHPat::rule()
      ->classes(Selector::classname(JsComponent::class))
      ->canOnlyDependOn()
      ->classes(
        Selector::classname(JsComponentDiscovery::class),
        // Canvas component source base classes and interfaces.
        Selector::inNamespace('Drupal\canvas\ComponentSource'),
        self::depsOfComponentSourceInterfaceImplementations(),
        // The base implementations.
        Selector::classname(JsonSchemaPropsComponentSourceBase::class),
        Selector::classname(JsonSchemaPropsComponentDiscoveryBase::class),
        Selector::classname(JsonSchemaPropsComponentInstanceUpdater::class),
        Selector::classname(JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator::class),
        // Config entity types powering code components.
        Selector::classname(ConfigEntityStorageInterface::class),
        Selector::classname(EntityTypeManagerInterface::class),
        Selector::classname(AssetLibrary::class),
        Selector::classname(BrandKit::class),
        Selector::classname(JavaScriptComponent::class),
        // Code component functionality.
        Selector::classname(GlobalImports::class),
        Selector::classname(ImportMapResponseAttachmentsProcessor::class),
        // Code components can be auto-saved, which requires awareness of them.
        Selector::inNamespace('Drupal\canvas\AutoSave'),
        Selector::classname(AutoSaveEntity::class),
        // Code components support so-called `content-entity-reference` props,
        // which is not part of the base class. Hence this needs to directly
        // depend on some of the supporting infra.
        // @see ::supportingInfraForJsonSchemaPropsComponentSourceBase()
        Selector::inNamespace('Drupal\canvas\PropExpressions\StructuredData'),
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::rewriteExampleUrl()
        Selector::classname(GeneratedUrl::class),
        Selector::classname(Url::class),
        // Code components mimick SDCs.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::getJavaScriptComponent()
        Selector::classname(ComponentNotFoundException::class),
        // Drupal core namespaces.
        Selector::inNamespace('Drupal\Component\Assertion'),
        Selector::inNamespace('Drupal\Core\Cache'),
        Selector::inNamespace('Drupal\Core\Extension'),
        Selector::inNamespace('Drupal\Core\File'),
        Selector::inNamespace('Drupal\Core\StringTranslation'),
        // e.g. \InvalidArgumentException
        Selector::isStandardClass(),
        // With one exception: a Canvas-provided fix for broken core infra.
        // @see https://www.drupal.org/project/drupal/issues/2169813
        Selector::classname(BetterEntityDataDefinition::class),
      )
      ->because('JsComponent is largely powered by JsonSchemaPropsComponentSourceBase and the additional dependencies are only for code component-specific logic.');
  }

  /**
   * The unavoidable dependencies for any ComponentSource plugin.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
   */
  private static function depsOfComponentSourceInterfaceImplementations(): SelectorInterface {
    return Selector::AnyOf(
      Selector::classname(ComponentSource::class),
      Selector::classname(Component::class),
      Selector::inNamespace('Drupal\Component\Plugin'),
      Selector::inNamespace('Drupal\Core\Plugin'),
      Selector::classname(EntityInterface::class),
      Selector::classname(FieldableEntityInterface::class),
      self::usesConstraintViolationValueObjects(),
      // @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
      Selector::classname(ContainerInterface::class),
      // @todo Refactor away before closing https://www.drupal.org/i/3520484.
      Selector::classname(ComponentTreeItem::class),
    );
  }

  /**
   * Much Canvas' infra exists for JSON Schema props-powered Copmonent Sources.
   *
   * In turn, this means that both the base classes and the concrete Component
   * Sources powered by these base classes MUST depend on this infra and SHOULD
   * minimize other dependencies.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentDiscoveryBase
   */
  private static function supportingInfraForJsonSchemaPropsComponentSourceBase(): SelectorInterface {
    return Selector::AnyOf(
      // @see ::propExpressionsAreStandAlone()
      Selector::inNamespace('Drupal\canvas\PropExpressions'),
      Selector::inNamespace('Drupal\canvas\PropShape'),
      // @see ::propSources()
      Selector::inNamespace('Drupal\canvas\PropSource'),
      // @see ::shapeMatcher()
      Selector::inNamespace('Drupal\canvas\ShapeMatcher'),
    );
  }

  private static function usesConstraintViolationValueObjects(): SelectorInterface {
    return Selector::AnyOf(
      Selector::classname(Constraint::class),
      Selector::classname(ConstraintViolation::class),
      Selector::classname(ConstraintViolationList::class),
      Selector::classname(ConstraintViolationListInterface::class),
    );
  }

}
