# Outdated Dependency Jira Ticket Creator

This application automates the process of tracking outdated Composer and npm dependencies by creating JIRA tickets for each outdated package.

## Features

*   **Composer Support:** Runs `composer outdated --format=json` in the project directory.
*   **NPM Support:** Runs `npm outdated --json` in the project directory.
*   **JIRA Integration:** Creates JIRA tickets in a specified project for each identified outdated dependency. Automatically sets ticket priority based on SemVer update level (MAJOR/MINOR/PATCH).
*   **Configurable:** Allows configuration of JIRA connection details (URL, API token, project key, issue type).
*   **Filtering:** Allows processing only specific packages specified via command-line options.
*   **Duplicate Prevention:** (Optional - currently commented out in `JiraService.php`) Checks for existing JIRA tickets for the same dependency and version before creating a new one.

## Requirements

*   PHP 8.0 or higher
*   Composer (if checking composer.json)
*   npm (if checking package.json)
*   Access to a JIRA Cloud instance with API access.
*   An API token for JIRA Cloud authentication.

## Configuration

The application requires JIRA connection details defined as environment variables.

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

## Workflow

1.  **Parse Arguments:** Get the path to the dependency file and check for options like `--dry-run` and `--package`.
2.  **Determine Working Directory & Script Directory:** Identify relevant paths.
3.  **Load Configuration:** Attempt to load `.env` file first from working directory, then from script directory (values from working directory take precedence). Read JIRA connection details from environment variables.
4.  **Validate Input:** Check if the dependency file exists and is either `composer.json` or `package.json`.
5.  **Determine Package Manager:** Identify the package manager type (composer/npm).
6.  **Execute Outdated Command:**
    *   If `composer.json`: Run `composer outdated --format=json` in the determined directory.
    *   If `package.json`: Run `npm outdated --json` in the determined directory.
7.  **Parse Output:** Extract the list of outdated dependencies, including package name, current version, and latest version.
8.  **Filter Dependencies:** If `--package` options were provided, filter the list to include only those specified packages.
9.  **Connect to JIRA:** Authenticate with the JIRA Cloud API using the provided credentials.
10. **Create JIRA Tickets:** For each remaining outdated dependency:
    *   (Optional) Check if a similar ticket already exists in JIRA.
    *   Construct the JIRA ticket details (summary, description, labels, etc.).
        *   Determines the SemVer level difference (MAJOR, MINOR, PATCH) between the current and latest version.
        *   Sets the JIRA ticket priority based on the SemVer level (e.g., MAJOR updates might be set to 'Emergency' or 'Highest', Minor to 'High', etc. - configurable in `JiraService.php`).
    *   If not a dry run, use the JIRA API to create the issue.
    *   Log the created ticket ID (or simulated key) or any errors.
11. **Report:** Output a summary of actions taken (tickets created/simulated, skipped, errors).

## Future Enhancements

*   Support for other package managers (e.g., `yarn`, `pip`) by extending the file detection and command execution logic.
*   Enable and refine duplicate ticket detection in `JiraService.php`.
*   Assigning tickets to specific users.
*   Setting custom fields in JIRA tickets.
*   Allowing configuration via a YAML or JSON file instead of environment variables.
*   Batching API requests to JIRA for performance.
*   Add options for specifying JIRA fields like labels or components via CLI arguments.
