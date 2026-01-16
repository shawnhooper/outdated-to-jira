# Outdated Dependency Jira Ticket Creator

[![CI](https://github.com/shawnhooper/outdated-to-jira/actions/workflows/ci.yml/badge.svg)](https://github.com/shawnhooper/outdated-to-jira/actions/workflows/ci.yml)

This application automates the process of tracking outdated Composer and npm dependencies by creating JIRA tickets for each outdated package.

## Features

*   **Composer & NPM Support:** Checks for outdated dependencies using `composer outdated` or `npm outdated`.
*   **JIRA Integration:** Creates JIRA tickets in a specified project for each identified outdated dependency. Automatically sets ticket priority based on SemVer update level (MAJOR/MINOR/PATCH).
*   **Configurable:** Accepts JIRA connection details (URL, API token, project key, issue type) via configuration array.
*   **Filtering:** Allows processing only specific packages.
*   **Duplicate Prevention:** Checks for existing JIRA tickets for the same dependency and version before creating a new one.
*   **PSR-3 Logging:** Uses a PSR-3 compliant logger for output.
*   **GitHub Action:** Provides a ready-to-use GitHub Action for automated checks in CI/CD pipelines.

## Requirements

*   PHP 8.2 or higher
*   Composer (if checking composer.json)
*   npm (if checking package.json)
*   Access to a JIRA Cloud instance with API access.
*   An API token for JIRA Cloud authentication.

## Installation (as a Library)

Add this package as a development dependency to your project using Composer:

```bash
composer require --dev shawnhooper/outdated-to-jira
```

## Library Usage

Instantiate the `DependencyCheckerService` and call its `process` method.

```php
<?php

require 'vendor/autoload.php';

use App\Service\DependencyCheckerService;
use Monolog\Logger; // Or your preferred PSR-3 Logger
use Monolog\Handler\StreamHandler;

// 1. Configure JIRA details (load from .env, config files, etc.)
$jiraConfig = [
    'jira_url' => 'https://your-domain.atlassian.net',
    'jira_user_email' => 'your-email@example.com',
    'jira_api_token' => getenv('JIRA_API_TOKEN'), // Get token securely!
    'jira_project_key' => 'YOUR_PROJECT_KEY',
    'jira_issue_type' => 'Task',
    'dry_run' => false, // Set to true to simulate
];

// 2. Create a PSR-3 Logger instance
$logger = new Logger('MyApplication');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// 3. Instantiate the service
$checkerService = new DependencyCheckerService($jiraConfig, $logger);

// 4. Define the path to your dependency file and optional filters
$dependencyFilePath = __DIR__ . '/path/to/your/composer.json'; // Or package.json
$packagesToFilter = ['some/package', 'another/vendor']; // Optional

// 5. Process the dependencies
try {
    $results = $checkerService->process($dependencyFilePath, $packagesToFilter);

    $logger->info("Processing Results:", $results);

    // Example: Check status for a specific package
    if (isset($results['some/package'])) {
        $status = $results['some/package'][DependencyCheckerService::RESULT_STATUS_KEY];
        $jiraKey = $results['some/package'][DependencyCheckerService::RESULT_JIRA_KEY];
        $logger->info("Status for some/package: {$status}" . ($jiraKey ? " ({$jiraKey})" : ''));
    }

} catch (\InvalidArgumentException $e) {
    $logger->error("Configuration or file path error: " . $e->getMessage());
} catch (\RuntimeException $e) {
    $logger->error("Processing error: " . $e->getMessage());
} catch (\Exception $e) {
    $logger->error("An unexpected error occurred: " . $e->getMessage());
}

```

### Return Values

The `process` method returns an associative array where keys are dependency names. Each value is another array containing:

*   `DependencyCheckerService::RESULT_STATUS_KEY`: A string indicating the outcome (e.g., `STATUS_TICKET_CREATED`, `STATUS_EXISTING_TICKET`, `STATUS_DRY_RUN_WOULD_CREATE`, `STATUS_FILTERED_OUT`, `STATUS_PROCESSING_ERROR`).
*   `DependencyCheckerService::RESULT_JIRA_KEY`: The created or found JIRA issue key (string), or `null` if not applicable.

## Testing

This project includes unit tests written using PHPUnit to verify the core components.

1.  **Install Dependencies:** Ensure you have installed the development dependencies:
    ```bash
    composer install
    ```
2.  **Run Tests:** Execute the test suite using the following command from the project root:
    ```bash
    vendor/bin/phpunit
    ```

## Continuous Integration

This project uses GitHub Actions for Continuous Integration (CI). The workflow is defined in `.github/workflows/ci.yml` and includes the following:

*   **Triggers:** Runs automatically on pushes to the `main` and `staging` branches, and on any pull request targeting these branches.
*   **Jobs:**
    *   **Testing:** Runs the PHPUnit test suite across multiple PHP versions (defined in the workflow matrix) to ensure compatibility.
*   **Caching:** Caches Composer dependencies to speed up build times.

This helps ensure that code changes maintain functionality and compatibility.

## Using as a GitHub Action

This tool can also be run as a GitHub Action within your own workflows.

**Example Workflow:**

```yaml
name: Check Dependencies

on:
  schedule:
    # Runs daily at midnight UTC
    - cron: '0 0 * * *'
  workflow_dispatch: # Allow manual trigger

jobs:
  # Job to check Composer dependencies
  check_composer:
    name: Check Composer Dependencies
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Outdated Dependency Check (Composer)
        uses: shawnhooper/outdated-to-jira@v1
        with:
          dependency-file: 'composer.json' # Adjust path if needed
          # Optional inputs:
          # dry-run: 'true'
          # packages: 'php-package1 vendor/package2'
          # github-token: ${{ secrets.GITHUB_TOKEN }} # For private git dependencies

          # Required JIRA configuration (use secrets!)
          jira-url: ${{ secrets.JIRA_URL }}
          jira-user-email: ${{ secrets.JIRA_USER_EMAIL }}
          jira-api-token: ${{ secrets.JIRA_API_TOKEN }}
          jira-project-key: ${{ secrets.JIRA_PROJECT_KEY }}
          jira-issue-type: 'Task'

  # Job to check NPM dependencies
  check_npm:
    name: Check NPM Dependencies
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Outdated Dependency Check (NPM)
        uses: shawnhooper/outdated-to-jira@staging # Or use @main or a specific tag/commit
        with:
          dependency-file: 'package.json' # Adjust path if needed
          # Optional inputs:
          # dry-run: 'true'
          # packages: 'react lodash'
          # github-token: ${{ secrets.GITHUB_TOKEN }} # For private git dependencies

          # Required JIRA configuration (use secrets!)
          jira-url: ${{ secrets.JIRA_URL }}
          jira-user-email: ${{ secrets.JIRA_USER_EMAIL }}
          jira-api-token: ${{ secrets.JIRA_API_TOKEN }}
          jira-project-key: ${{ secrets.JIRA_PROJECT_KEY }}
          jira-issue-type: 'Task' # Or your desired issue type

```

**Action Inputs:**

*   `dependency-file` (Required): Path to `composer.json` or `package.json` relative to the root of the repository where the workflow runs.
*   `dry-run` (Optional): Set to `'true'` to simulate without creating tickets. Defaults to `'false'`.
*   `packages` (Optional): A space-separated string of package names to filter for.
*   `github-token` (Optional): GitHub token for private git dependencies (e.g., `secrets.GITHUB_TOKEN` or a PAT with `repo` scope). Without it, private GitHub VCS deps will fail over HTTPS.
*   `jira-url` (Required): Base URL of your JIRA instance.
*   `jira-user-email` (Required): Email address for JIRA API authentication.
*   `jira-api-token` (Required): JIRA API token for authentication (**Use GitHub Secrets**).
*   `jira-project-key` (Required): JIRA project key.
*   `jira-issue-type` (Required): JIRA issue type name (e.g., `Task`, `Bug`).

**Important:** Store sensitive values like `JIRA_API_TOKEN`, `JIRA_USER_EMAIL`, and potentially `JIRA_URL` as encrypted [GitHub Secrets](https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions) in the repository that *uses* this action.
