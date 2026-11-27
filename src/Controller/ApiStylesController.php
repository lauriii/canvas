<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\StyleLibrary;
use Drupal\canvas\Style\ComponentStyles;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lists available component styles for the editor, the CLI, and builds.
 *
 * Experimental: follows core's Style API, which is not committed yet.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::definitions()
 * @see openapi.yml `StyleDefinition`
 */
final class ApiStylesController {

  public function __construct(
    private readonly ComponentStyles $componentStyles,
    private readonly AccountInterface $currentUser,
  ) {}

  public function __invoke(Request $request): CacheableJsonResponse {
    // The editor asks for the draft brand kit; the CLI and builds do not. The
    // brand kit's own UI asks for definitions before the brand kit applies.
    $preview = $request->query->get('preview') === '1';
    $unbranded = $request->query->get('unbranded') === '1';
    $build = fn (): array => [
      'styles' => \array_values(\array_map(
        static fn (array $definition): array => [
          ...$definition,
          'options' => \array_values($definition['options']),
          // JSON objects, even when empty.
          'fallbacks' => (object) $definition['fallbacks'],
          'notOffered' => (object) $definition['notOffered'],
        ],
        $unbranded ? $this->componentStyles->availableDefinitions() : $this->componentStyles->definitions(),
      )),
      'hiddenStyles' => (object) ($unbranded ? [] : $this->componentStyles->hiddenStyles()),
    ];
    $data = $preview ? $this->componentStyles->runInPreview($build) : $build();
    $cacheability = $this->componentStyles->definitionsCacheability();
    $response = new CacheableJsonResponse([
      'active' => $this->componentStyles->isActive(),
      ...$data,
      'permissions' => [
        'use' => $this->currentUser->hasPermission('use component styles'),
        'administer' => $this->currentUser->hasPermission(StyleLibrary::ADMIN_PERMISSION),
        'custom' => $this->currentUser->hasPermission('use custom style values'),
      ],
    ]);
    $response->addCacheableDependency(
      (new CacheableMetadata())
        ->setCacheTags([...$cacheability['tags'], ...($preview ? [AutoSaveManager::CACHE_TAG] : [])])
        ->setCacheContexts([
          ...$cacheability['contexts'],
          'user.permissions',
          'url.query_args:preview',
          'url.query_args:unbranded',
        ])
    );
    return $response;
  }

}
