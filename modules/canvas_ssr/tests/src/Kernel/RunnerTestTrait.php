<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Gives a kernel test a runner to render on.
 *
 * Tests don't name a runner. CANVAS_SSR_TEST_RUNNER_CLASS names the runner's
 * class, and Deno's when it's not set. The class's module, the second segment
 * of its namespace, is enabled, and the selector must select that class.
 *
 * Tests don't check for PHP extensions either. Where the runner isn't
 * available, the selector selects something else, and the test is skipped.
 * RunnerSelectedTest fails instead, so the run as a whole doesn't pass.
 *
 * Every class using this is in the group named below. Where no runner can
 * run, exclude that group, either with --exclude-group or by default in the
 * PHPUnit configuration:
 * @code
 * <groups>
 *   <exclude>
 *     <group>canvas_ssr_requires_runner</group>
 *   </exclude>
 * </groups>
 * @endcode
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsRunner\RunnerSelectedTest
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase
 */
trait RunnerTestTrait {

  /**
   * Environment variable naming the runner class to test.
   */
  private const RUNNER_CLASS_VARIABLE = 'CANVAS_SSR_TEST_RUNNER_CLASS';

  /**
   * The test group every class using this trait is in.
   */
  private const RUNNER_TEST_GROUP = 'canvas_ssr_requires_runner';

  /**
   * Installs the runner under test and returns the runner, or skips.
   *
   * Call from setUp() after parent::setUp(), before anything renders. The
   * runner module is enabled here rather than listed in $modules because its
   * name is only known at run time.
   *
   * @return \Drupal\canvas_ssr\JsRunner\RunnerInterface
   *   The runner every caller gets, known to have selected the runner under
   *   test.
   */
  protected function installRunner(): RunnerInterface {
    $runner = $this->selectRunner();
    if ($runner === NULL) {
      self::markTestSkipped($this->runnerNotSelectedMessage());
    }
    return $runner;
  }

  /**
   * Enables the runner under test, and returns the runner if it's selected.
   *
   * @return \Drupal\canvas_ssr\JsRunner\RunnerInterface|null
   *   The runner every caller gets, or NULL if some other runner is
   *   selected.
   */
  protected function selectRunner(): ?RunnerInterface {
    $this->enableModules([$this->runnerModule()]);

    $selector = $this->container->get(RunnerSelector::class);
    \assert($selector instanceof RunnerSelector);
    $class = $this->runnerClass();
    if (!$selector->select() instanceof $class) {
      return NULL;
    }
    $runner = $this->container->get(RunnerInterface::class);
    \assert($runner instanceof RunnerInterface);

    return $runner;
  }

  /**
   * Explains why the runner under test wasn't selected.
   */
  protected function runnerNotSelectedMessage(): string {
    return \sprintf(
      'The selector did not select %s. Either it cannot run here, or another runner outranks it. Set %s to a runner that can run, or, if no runner can run on this system and you want the canvas_ssr tests to pass anyway, exclude the "%s" group with --exclude-group %s.',
      $this->runnerClass(),
      self::RUNNER_CLASS_VARIABLE,
      self::RUNNER_TEST_GROUP,
      self::RUNNER_TEST_GROUP,
    );
  }

  /**
   * Names the runner class under test.
   */
  protected function runnerClass(): string {
    return self::runnerClassUnderTest();
  }

  /**
   * Names the runner class under test, where there's no test instance.
   */
  protected static function runnerClassUnderTest(): string {
    $class = \getenv(self::RUNNER_CLASS_VARIABLE);
    return \is_string($class) && $class !== ''
      ? \ltrim($class, '\\')
      : DenoRunner::class;
  }

  /**
   * Names the module that provides the runner class under test.
   *
   * The class needn't exist yet, since a runner's service can declare its
   * class when it's built. So this reads the name only.
   */
  protected function runnerModule(): string {
    $class = $this->runnerClass();
    $segments = \explode('\\', $class);
    if (\count($segments) < 3 || $segments[0] !== 'Drupal') {
      self::fail(\sprintf(
        '%s names "%s", which is not a module\'s class. It must look like Drupal\\<module>\\…',
        self::RUNNER_CLASS_VARIABLE,
        $class,
      ));
    }

    $module_list = \Drupal::service(ModuleExtensionList::class);
    \assert($module_list instanceof ModuleExtensionList);
    if (!$module_list->exists($segments[1])) {
      self::fail(\sprintf(
        '%s names "%s", but there is no "%s" module on this site.',
        self::RUNNER_CLASS_VARIABLE,
        $class,
        $segments[1],
      ));
    }
    return $segments[1];
  }

}
