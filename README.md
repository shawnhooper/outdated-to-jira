# Outdated Dependency Jira Ticket Creator

[![CI](https://github.com/shawnhooper/outdated-to-jira/actions/workflows/ci.yml/badge.svg)](https://github.com/shawnhooper/outdated-to-jira/actions/workflows/ci.yml)

This application automates the process of tracking outdated Composer and npm dependencies by creating JIRA tickets for each outdated package.

## Features

*   **Composer Support:** Runs `composer outdated --format=json` in the project directory.
*   **NPM Support:** Runs `npm outdated --json` in the project directory.
*   **JIRA Integration:** Creates JIRA tickets in a specified project for each identified outdated dependency. Automatically sets ticket priority based on SemVer update level (MAJOR/MINOR/PATCH).
*   **Configurable:** Allows configuration of JIRA connection details (URL, API token, project key, issue type).
*   **Filtering:** Allows processing only specific packages specified via command-line options.
*   **Duplicate Prevention:** (Optional - currently commented out in `JiraService.php`) Checks for existing JIRA tickets for the same dependency and version before creating a new one.

## Requirements

*   PHP 8.2 or higher
*   Composer (if checking composer.json)
*   npm (if checking package.json)
*   Access to a JIRA Cloud instance with API access.
*   An API token for JIRA Cloud authentication.

## Configuration

**Note:** The following instructions using `.env` files apply when running the tool directly from the command line (CLI). For configuration when using the GitHub Action, please refer to the "Using as a GitHub Action" section.

The application requires JIRA connection details defined as environment variables when run via CLI.

It loads settings from `.env` files in a layered approach:

1.  **Project Directory:** It first looks for and loads a `.env` file in the directory containing the `<path/to/composer.json|package.json>` file passed as an argument.
2.  **Script Directory:** It then looks for and loads a `.env` file in the directory where the `outdated-to-jira` script itself resides (if this is a different location and the file exists).

**Precedence:** If a variable (e.g., `JIRA_URL`) is defined in *both* `.env` files, the value from the **Project Directory**'s `.env` file will be used. The Script Directory's `.env` acts as a fallback for variables not defined in the project's file.

Create a `.env` file (or copy `.env.example` to `.env`) and set the following variables:

*   `JIRA_URL`: The base URL of your JIRA Cloud instance (e.g., `https://your-domain.atlassian.net`).
*   `JIRA_USER_EMAIL`: The email address associated with the JIRA API token.
*   `JIRA_API_TOKEN`: The JIRA Cloud API token.
*   `JIRA_PROJECT_KEY`: The project key where tickets should be created (e.g., `PROJ`).
*   `JIRA_ISSUE_TYPE`: The JIRA issue type for the tickets (e.g., `Task`, `Bug`).

## Usage

The application is run from the command line, providing the path to the dependency file:

```bash
php outdated-to-jira <path/to/composer.json|package.json> [--dry-run] [--package=<name> ...]
```

*   `<path/to/composer.json|package.json>`: (Required) The full or relative path to the `composer.json` or `package.json` file you want to check.
*   `--dry-run` | `-d`: (Optional) Output the tickets that would be created without actually creating them in JIRA.
*   `--package=<name>` | `-p <name>`: (Optional, Repeatable) Only process updates for the specified package name(s). If omitted, all outdated direct dependencies are processed.

**Examples:**

```bash
# Check a composer project and simulate ticket creation
php outdated-to-jira /var/www/my-php-project/composer.json --dry-run

# Check an npm project and only create tickets for 'react' and 'react-dom' if outdated
php outdated-to-jira ./frontend-app/package.json --package=react --package=react-dom

# Check a composer project for a specific package using the shortcut
php outdated-to-jira ../backend/composer.json -p psr/log
```

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
  check_and_create_tickets:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Outdated Dependency Check
        uses: shawnhooper/outdated-to-jira@staging # Or use @main or a specific tag/commit
        with:
          dependency-file: 'path/to/your/composer.json' # Or package.json
          # Optional inputs:
          # dry-run: 'true' 
          # packages: 'package1 package2'
          
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
*   `jira-url` (Required): Base URL of your JIRA instance.
*   `jira-user-email` (Required): Email address for JIRA API authentication.
*   `jira-api-token` (Required): JIRA API token for authentication (**Use GitHub Secrets**).
*   `jira-project-key` (Required): JIRA project key.
*   `jira-issue-type` (Required): JIRA issue type name (e.g., `Task`, `Bug`).

**Important:** Store sensitive values like `JIRA_API_TOKEN`, `JIRA_USER_EMAIL`, and potentially `JIRA_URL` as encrypted [GitHub Secrets](https://docs.github.com/en/actions/security-guides/using-secrets-in-github-actions) in the repository that *uses* this action.
