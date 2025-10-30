<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

/**
 * Defines what prop source types are available, and instantiates them.
 *
 * Prop sources are not pluggable; Canvas only supports a hard-coded set of prop
 * sources, and that list is defined by this enum. The ::parse() method will,
 * given a prop source's array representation, instantiate the appropriate
 * concrete PropSourceBase object. For example:
 * @code
 * $prop_source = PropSource::parse([
 *   'sourceType' => 'static:field_item:string',
 *   // ...
 *   // Additional information needed to instantiate this prop source goes here.
 *   // This will vary for different types of prop sources.
 * ]);
 * @endcode
 *
 * For each enum case (aka a prop source type) a corresponding PropSourceBase
 * subclass must exist.
 *
 * @phpstan-import-type PropSourceArray from PropSourceBase
 * @phpstan-import-type AdaptedPropSourceArray from PropSourceBase
 * @phpstan-import-type DefaultRelativeUrlPropSourceArray from PropSourceBase
 * @phpstan-import-type HostEntityUrlPropSourceArray from PropSourceBase
 */
enum PropSource: string {

  case Adapter = 'adapter';
  case DefaultRelativeUrl = 'default-relative-url';
  case Dynamic = 'dynamic';
  case Static = 'static';
  case HostEntityUrl = 'host-entity-url';

  /**
   * Returns the proper type prefix for a prop source.
   *
   * @param \Drupal\canvas\PropSource\PropSourceBase|class-string $prop_source
   *   A prop source object, or the name of a PropSourceBase subclass.
   *
   * @return string
   *   The prefix -- a string identifying the prop source type, which can be
   *   passed to self::tryFrom().
   */
  public static function getTypePrefix(PropSourceBase|string $prop_source): string {
    if ($prop_source instanceof PropSourceBase) {
      $prop_source = $prop_source::class;
    }
    return match ($prop_source) {
      AdaptedPropSource::class => self::Adapter->value,
      DefaultRelativeUrlPropSource::class => self::DefaultRelativeUrl->value,
      HostEntityUrlPropSource::class => self::HostEntityUrl->value,
      StaticPropSource::class => self::Static->value,
      DynamicPropSource::class => self::Dynamic->value,
      default => throw new \LogicException('Unknown prop source class.'),
    };
  }

  /**
   * @param PropSourceArray|AdaptedPropSourceArray|DefaultRelativeUrlPropSourceArray|HostEntityUrlPropSourceArray $prop_source
   */
  public static function parse(array $prop_source): PropSourceBase {
    $source_type_prefix = strstr($prop_source['sourceType'], PropSourceBase::SOURCE_TYPE_PREFIX_SEPARATOR, TRUE);
    // If the prefix separator is not present, then use the full source type.
    // For example: `dynamic` does not need a more detailed source type.
    // @see \Drupal\canvas\PropSource\DynamicPropSource::__toString()
    if ($source_type_prefix === FALSE) {
      $source_type_prefix = $prop_source['sourceType'];
    }
    return match (self::tryFrom($source_type_prefix)) {
      // The DefaultRelativeUrlPropSource allows referring to a
      // component-defined default value for a URL prop shape at storage time,
      // but will then be transformed to a resolvable (working) absolute or
      // root-relative URL at run time.
      // @see \Drupal\canvas\ComponentSource\UrlRewriteInterface
      self::DefaultRelativeUrl => DefaultRelativeUrlPropSource::parse($prop_source),
      // The HostEntityUrlPropSource allows generating a URL to the host entity.
      // TRICKY: currently, only component trees in ContentTemplates are allowed
      // to contain such prop sources, but the host entity is NOT a
      // ContentTemplate config entity, but a fieldable entity of the entity
      // type and bundle that the ContentTemplate targets.
      // @todo Possibly support different link templates and options in
      //   https://www.drupal.org/i/3551455.
      self::HostEntityUrl => HostEntityUrlPropSource::parse($prop_source),
      // The AdaptedPropSource is the exception: it composes multiple other prop
      // sources, and those are listed under `adapterInputs`.
      // @phpstan-ignore-next-line argument.type
      self::Adapter => AdaptedPropSource::parse($prop_source),
      self::Static => StaticPropSource::parse($prop_source),
      self::Dynamic => DynamicPropSource::parse($prop_source),
      default => throw new \LogicException('Unknown source type.'),
    };
  }

}
