<?php

declare(strict_types=1);

namespace Drupal\canvas\CoreBugFix;

use Drupal\Core\Config\Entity\Query\Query;

/**
 * @internal
 *
 * @todo Fix upstream in core in https://www.drupal.org/project/drupal/issues/2862699
 */
final class ConfigEntityQuery extends Query {

  /**
   * Comparison function for uasort to handle multiple sorts like SQL does.
   *
   * @param array $a
   *   First config item data.
   * @param array $b
   *   Second config item data.
   * @param int $index
   *   Index into the list of sorts to be applied.
   *
   * @return int
   *   0 if the two items sort the same, -1 if $a is less than $b, 1 otherwise.
   */
  protected function recursiveCmp(array $a, array $b, $index = 0) {
    $direction = $this->sort[$index]['direction'] == 'ASC' ? -1 : 1;
    $field = $this->sort[$index]['field'];
    if ($a[$field] == $b[$field]) {
      $index++;
      if (isset($this->sort[$index])) {
        return $this->recursiveCmp($a, $b, $index);
      }
      return 0;
    }
    return ($a[$field] < $b[$field]) ? $direction : -$direction;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Invoke entity query alter hooks.
    $this->alter();

    // Load the relevant config records.
    $configs = $this->loadRecords();

    // Apply conditions.
    $result = $this->condition->compile($configs);

    // Apply sort settings.
    if ($this->sort) {
      uasort($result, [$this, 'recursiveCmp']);
    }

    // Let the pager do its work.
    $this->initializePager();

    if ($this->range) {
      $result = array_slice($result, $this->range['start'], $this->range['length'], TRUE);
    }
    if ($this->count) {
      return count($result);
    }

    // Create the expected structure of entity_id => entity_id. Config
    // entities have string entity IDs.
    foreach ($result as $key => &$value) {
      $value = (string) $key;
    }
    return $result;
  }

}
