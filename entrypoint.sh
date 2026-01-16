#!/bin/sh -l

# Exit immediately if a command exits with a non-zero status.
set -e

# Optional: Print inputs for debugging
echo "Starting action..."
echo "Dependency File: ${DEPENDENCY_FILE}"
echo "Dry Run: ${DRY_RUN}"
echo "Packages: ${PACKAGES}"
echo "JIRA URL: ${JIRA_URL}"
echo "JIRA Project: ${JIRA_PROJECT_KEY}"
echo "JIRA Issue Type: ${JIRA_ISSUE_TYPE}"

# Force HTTPS for GitHub git URLs to avoid SSH in minimal containers.
# If a token is provided, embed it so private repos can be accessed.
if [ -n "${GIT_AUTH_TOKEN}" ]; then
  git config --global url."https://x-access-token:${GIT_AUTH_TOKEN}@github.com/".insteadOf "git@github.com:"
  git config --global url."https://x-access-token:${GIT_AUTH_TOKEN}@github.com/".insteadOf "ssh://git@github.com/"
elif [ -n "${GITHUB_TOKEN}" ]; then
  git config --global url."https://x-access-token:${GITHUB_TOKEN}@github.com/".insteadOf "git@github.com:"
  git config --global url."https://x-access-token:${GITHUB_TOKEN}@github.com/".insteadOf "ssh://git@github.com/"
else
  git config --global url."https://github.com/".insteadOf "git@github.com:"
  git config --global url."https://github.com/".insteadOf "ssh://git@github.com/"
fi

# Construct the command
CMD="php /app/run-action.php"

# Add dependency file argument (relative to GITHUB_WORKSPACE)
# GitHub Actions automatically mounts the workspace at /github/workspace
# We need to reference the file within that mounted volume
FULL_DEPENDENCY_PATH="/github/workspace/${DEPENDENCY_FILE}"
CMD="${CMD} ${FULL_DEPENDENCY_PATH}"

# Add --dry-run flag if DRY_RUN is true
if [ "${DRY_RUN}" = "true" ]; then
  CMD="${CMD} --dry-run"
fi

# Add --package options if PACKAGES is not empty
if [ -n "${PACKAGES}" ]; then
  # Split the space-separated string into arguments
  for package in ${PACKAGES};
  do
    CMD="${CMD} --package=${package}"
  done
fi

# Execute the command using the new action runner script
echo "Running command: ${CMD}"
${CMD}

echo "Action finished." 
