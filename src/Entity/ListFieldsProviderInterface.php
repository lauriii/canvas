<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

/**
 * A component tree config entity whose repetitions declare named fields.
 *
 * A repeating renderer (a query-results template rendering its tree once per
 * result row, the List element rendering its item template per item) declares
 * the fields each iteration provides. The editor offers those fields as
 * binding targets for component props, stored as list-field prop sources and
 * resolved per iteration through ListFieldContext.
 *
 * @internal
 *
 * @see \Drupal\canvas\PropSource\ListFieldPropSource
 * @see \Drupal\canvas\PropSource\ListFieldContext
 */
interface ListFieldsProviderInterface extends ComponentTreeEntityInterface {

  /**
   * The fields each iteration of this template declares.
   *
   * @return array<string, string>
   *   Field labels keyed by field name; the names are what list-field prop
   *   sources store and what the renderer pushes onto ListFieldContext.
   */
  public function getDeclaredListFields(): array;

}
