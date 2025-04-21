# Outdated Dependency Jira Ticket Creator

This application automates the process of tracking outdated Composer and npm dependencies by creating JIRA tickets for each outdated package.

## Features

*   **Composer Support:** Runs `composer outdated` and parses its output.
*   **NPM Support:** Runs `npm outdated` and parses its output.
*   **JIRA Integration:** Creates JIRA tickets in a specified project for each identified outdated dependency.
*   **Configurable:** Allows configuration of JIRA connection details (URL, API token, project key) and package manager paths.
*   **Duplicate Prevention:** (Optional) Checks for existing JIRA tickets for the same dependency and version before creating a new one.

## Requirements

*   PHP 8.0 or higher
*   Composer
*   Access to a JIRA Cloud instance with API access.
*   An API token for JIRA Cloud authentication.

## Configuration

The application will require configuration, likely through environment variables or a configuration file (`config.php` or `.env`), for the following:

*   `JIRA_URL`: The base URL of your JIRA Cloud instance (e.g., `https://your-domain.atlassian.net`).
*   `JIRA_USER_EMAIL`: The email address associated with the JIRA API token.
*   `JIRA_API_TOKEN`: The JIRA Cloud API token.
*   `JIRA_PROJECT_KEY`: The project key where tickets should be created (e.g., `PROJ`).
*   `JIRA_ISSUE_TYPE`: The JIRA issue type for the tickets (e.g., `Task`, `Bug`).
*   `COMPOSER_PROJECT_PATH`: The path to the PHP project directory containing the `composer.json` file.
*   `NPM_PROJECT_PATH`: The path to the Node.js project directory containing the `package.json` file.

## Usage

The application will be run from the command line:

```bash
php outdated-to-jira [--composer] [--npm] [--dry-run]
```

*   `--composer`: Process outdated Composer dependencies.
*   `--npm`: Process outdated npm dependencies.
*   If neither `--composer` nor `--npm` is specified, both are processed.
*   `--dry-run`: Output the tickets that would be created without actually creating them in JIRA.

## Workflow

1.  **Parse Arguments:** Determine which package managers to check (Composer, npm, or both) and if it's a dry run.
2.  **Execute Outdated Command:**
    *   For Composer: Run `composer outdated --format=json` in the `COMPOSER_PROJECT_PATH`.
    *   For npm: Run `npm outdated --json` in the `NPM_PROJECT_PATH`.
3.  **Parse Output:** Extract the list of outdated dependencies, including package name, current version, and latest version.
4.  **Connect to JIRA:** Authenticate with the JIRA Cloud API using the provided credentials.
5.  **Create JIRA Tickets:** For each outdated dependency:
    *   (Optional) Check if a similar ticket already exists in JIRA.
    *   Construct the JIRA ticket details (summary, description, labels, etc.).
    *   Use the JIRA API to create the issue.
    *   Log the created ticket ID or any errors.
6.  **Report:** Output a summary of actions taken (tickets created, skipped, errors).

## Future Enhancements

*   Support for other package managers (e.g., `yarn`, `pip`).
*   More sophisticated duplicate ticket detection.
*   Assigning tickets to specific users.
*   Setting custom fields in JIRA tickets.
*   Allowing configuration via a YAML or JSON file instead of environment variables.
*   Batching API requests to JIRA.
