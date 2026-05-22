To install the module, add this line to your `settings.php` file if it is not already present:

```php
$settings['extension_discovery_scan_tests'] = TRUE;
```

Then, install the module using Drush:

```bash
drush en canvas_ai_agents_test
```

### Running Tests

1. Navigate to `/admin/config/ai/agents-test/group` in your Drupal admin interface.
2. Click on **Import AI Agent Test Group**.
3. Select the test group files from the `tests` folder within this module and import them.
4. Once the tests are imported, click **Run test group**.
5. Select the AI model you wish to use for the tests.
6. All tests within the group will begin executing automatically.

### Cleanup

After testing is complete, ensure you uninstall the module:

```bash
drush pmu canvas_ai_agents_test
```
