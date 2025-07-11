<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\AutoSave;

use Drupal\experience_builder\Entity\JavaScriptComponent;

/**
 * Tests auto-save conflict handling for code components.
 *
 * @see \Drupal\experience_builder\Entity\JavaScriptComponent
 */
final class AutoSaveConflictJavaScriptComponentTest extends AutoSaveConflictConfigTestBase {

  protected string $updateKey = 'name';

  protected function setUpEntity(): void {
    $this->entity = JavaScriptComponent::createFromClientSide([
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
    ]);
    $this->entity->save();
  }

}
