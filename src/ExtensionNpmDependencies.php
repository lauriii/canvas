<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Collects the npm packages that installed modules and themes declare.
 *
 * A module that ships JavaScript for code components can publish it on npm
 * and declare the package in its info file, so that a CLI project paired with
 * the site depends on the same package at the same version:
 *
 * @code
 * canvas:
 *   npm:
 *     '@acme/canvas-forms': 1.2.0
 * @endcode
 *
 * The version is the one the extension was built and tested against. It is
 * written into the project's package.json by `canvas pull`, so the copy the
 * CLI bundles and Workbench previews is the copy the extension expects.
 *
 * @internal
 */
final class ExtensionNpmDependencies {

  public const string INFO_KEY = 'npm';

  /**
   * An npm package name: optional scope, then lowercase URL-safe characters.
   *
   * @see https://github.com/npm/validate-npm-package-name
   */
  private const string PACKAGE_NAME_PATTERN = '/^(?:@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';

  /**
   * An exact semantic version, optionally with a pre-release or build suffix.
   *
   * Only an exact version is accepted: it is what the extension was built
   * against, and it is what a build can compare an installed copy to without a
   * range resolver. Anything else (a range, a tag, a URL, a path) is rejected
   * so an info file cannot make a project install arbitrary sources.
   */
  private const string VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
  ) {}

  /**
   * @return array<string, string>
   *   Package name => version, sorted by package name. Empty when no installed
   *   extension declares any.
   */
  public function getDependencies(): array {
    $dependencies = [];
    foreach ([$this->moduleExtensionList, $this->themeExtensionList] as $extension_list) {
      foreach ($extension_list->getAllInstalledInfo() as $info) {
        $declared = $info['canvas'][self::INFO_KEY] ?? [];
        if (!\is_array($declared)) {
          continue;
        }
        foreach ($declared as $package => $version) {
          if (self::isValidDeclaration($package, $version)) {
            $dependencies[$package] = $version;
          }
        }
      }
    }
    \ksort($dependencies);
    return $dependencies;
  }

  /**
   * Whether an entry is a well-formed npm package name and an exact version.
   */
  public static function isValidDeclaration(mixed $package, mixed $version): bool {
    return \is_string($package)
      && \is_string($version)
      && \strlen($package) <= 214
      && \preg_match(self::PACKAGE_NAME_PATTERN, $package) === 1
      && \preg_match(self::VERSION_PATTERN, $version) === 1;
  }

}
