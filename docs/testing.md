# Testing

Automated suites of `commercekings/module-consent`. Start with the [README](../README.md).

From the Magento root, with the package installed:
```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Unit
```
`Test/Unit/InMemoryConsentStore.php` mirrors the store's SQL rules (unique choice, marks) so the lifecycle tests run without a database.

Run the integration tests against a local Magento database with the module schema installed:
```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/ConsentMappingTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/WithdrawalWorkflowTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/NativeExportTest.php
```
The mapping test uses temporary tables and checks stored consent validation and guarded job updates across two websites, including removal and restoration of mappings. It leaves application records unchanged. To run from another directory, set `MAGENTO_ROOT` to the installed Magento root; the test supports Composer installs and source mounts without relying on package directory depth.

The withdrawal workflow test runs banner and email withdrawals through the production transactions against temporary tables: cleanup after commit, retries of failed handlers, later grants preserved, recovery after an interruption and the native lock.

The native export test checks filter metadata, paginated SQL reads, historical wording, CSV escaping, exclusions, configuration scope and the standard export consumer's finished file. Its connection-local temporary tables and export files are removed after each test; it does not consume the application's queue.
