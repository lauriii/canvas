<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
   * so an info file cannot make a project install arbitrary sources. This is
   * the official SemVer 2.0.0 pattern, so leading zeros and empty identifiers
   * are rejected too.
   *
   * @see https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string
   */
  private const string VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array<string, string>
   *   Package name => version, sorted by package name. Empty when no installed
   *   extension declares any.
   */
  public function getDependencies(): array {
    $dependencies = [];
    // Which extension declared each package, to name both sides of a conflict.
    $declared_by = [];
    foreach ([$this->moduleExtensionList, $this->themeExtensionList] as $extension_list) {
      foreach ($extension_list->getAllInstalledInfo() as $extension => $info) {
        $declared = $info['canvas'][self::INFO_KEY] ?? [];
        if (!\is_array($declared)) {
          continue;
        }
        foreach ($declared as $package => $version) {
          if (!self::isValidDeclaration($package, $version)) {
            continue;
          }
          if (isset($dependencies[$package]) && $dependencies[$package] !== $version) {
            // Two extensions want different versions of one package. A project
            // can only install one, so the higher wins, deterministically, and
            // the disagreement is logged for the site owner to settle: the
            // extension that wanted the lower version gets a newer copy than it
            // declared, exactly as when two modules disagree on any library.
            $this->logger->warning('Extensions @a and @b declare different versions of the npm package @package (@va and @vb). Using @chosen.', [
              '@a' => $declared_by[$package],
              '@b' => $extension,
              '@package' => $package,
              '@va' => $dependencies[$package],
              '@vb' => $version,
              '@chosen' => \version_compare($version, $dependencies[$package], '>') ? $version : $dependencies[$package],
            ]);
            if (\version_compare($version, $dependencies[$package], '<=')) {
              continue;
            }
          }
          $dependencies[$package] = $version;
          $declared_by[$package] = $extension;
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
