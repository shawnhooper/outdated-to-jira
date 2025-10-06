<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\Dependency;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class JiraService
{
    public const DRY_RUN_WOULD_CREATE = 'DRY_RUN_WOULD_CREATE'; // Add constant

    private HttpClient $httpClient;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config, ?LoggerInterface $logger = null, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $httpClient ?? $this->createHttpClient();
    }

    private function createHttpClient(): HttpClient
    {
        $stack = HandlerStack::create();

        // Middleware for Authentication
        // Only add auth if token and user are available (relevant for non-dry-run)
        if (!empty($this->config['jira_user_email']) && !empty($this->config['jira_api_token'])) {
            $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
                $credentials = base64_encode($this->config['jira_user_email'] . ':' . $this->config['jira_api_token']);
                return $request->withHeader('Authorization', 'Basic ' . $credentials)
                               ->withHeader('Accept', 'application/json')
                               ->withHeader('Content-Type', 'application/json');
            }));
        } else {
             $this->logger->debug('JIRA user email or API token not provided, skipping Auth middleware setup.');
        }

        // Middleware for Logging (Restoring)
        // Re-enable the logger
        $stack->push(Middleware::log($this->logger, new \GuzzleHttp\MessageFormatter(
            \GuzzleHttp\MessageFormatter::DEBUG
        )));

        $guzzleConfig = [
            'timeout' => 30.0,
            'handler' => $stack,
            'http_errors' => false, // We'll handle errors manually based on status code
        ];

        // Only set base_uri if JIRA_URL is provided
        if (!empty($this->config['jira_url'])) {
             $guzzleConfig['base_uri'] = rtrim($this->config['jira_url'], '/') . '/rest/api/3/';
        } else {
             $this->logger->debug('JIRA URL not provided, skipping base_uri setup for Guzzle.');
        }

        return new HttpClient($guzzleConfig);
    }

     /**
     * Creates JIRA issues for a list of outdated dependencies.
     *
     * @param Dependency[] $dependencies
     * @return array<string, string|null> Map of dependency name to created JIRA issue key or null if failed/skipped.
     */
    // REMOVE THIS ENTIRE METHOD - START
    /*
    public function createTicketsForDependencies(array $dependencies): array
    {
        $results = [];
        $count = 0;
        foreach ($dependencies as $dependency) {
            $count++;
            $results[$dependency->name] = $this->createTicket($dependency);
            // Optional: Add a small delay between API calls to avoid rate limiting
            // usleep(500000); // 0.5 seconds
        }
        return $results;
    }
    */
    // REMOVE THIS ENTIRE METHOD - END


    /**
     * Creates a single JIRA ticket for an outdated dependency.
     */
    public function createTicket(Dependency $dependency): ?string
    {
        // --- Check for existing duplicate ticket ---
        $existingKey = $this->findExistingTicket($dependency);
        if ($existingKey !== null) {
            $this->logger->info(
                'Skipping creation: Found existing open ticket.',
                [
                    'key' => $existingKey,
                    'dependency' => $dependency->name
                ]
            ); // phpcs:ignore Generic.Files.LineLength.TooLong
            return $existingKey;
        }

        // --- If no existing ticket, check for dry run before attempting creation ---
        if ($this->config['dry_run']) {
             $this->logger->info(
                 '[Dry Run] No existing ticket found. Would create new ticket.',
                 [
                    'dependency' => $dependency->name
                 ]
             );
             return self::DRY_RUN_WOULD_CREATE; // Return special indicator
        }

        // --- Determine SemVer level for Priority ---
        $semVerLevel = $this->getSemVerLevel($dependency->currentVersion, $dependency->latestVersion);
        // Assuming these Priority names exist in JIRA
        $priorityName = match ($semVerLevel) {
            'MAJOR' => 'Emergency',
            'MINOR' => 'High',
            'PATCH' => 'Medium',
            default => 'Low' // Default for UNKNOWN
        }; // phpcs:ignore Generic.Files.LineLength.TooLong

        // --- Proceed with actual creation if not dry run and no existing ticket ---
        $summary = sprintf(
            'Update %s package %s from %s to %s',
            ucfirst($dependency->packageManager),
            $dependency->name,
            $dependency->currentVersion,
            $dependency->latestVersion
        ); // phpcs:ignore Generic.Files.LineLength.TooLong

        $description = sprintf(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            "The %s package *%s* is outdated.\n\nCurrent version: %s\nLatest version: %s\n\nPlease update the package and test accordingly.",
            $dependency->packageManager,
            $dependency->name,
            $dependency->currentVersion,
            $dependency->latestVersion
        );

        // Basic JIRA description format (CommonMark)
        $descriptionPayload = [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => sprintf(
                                'The %s package ',
                                $dependency->packageManager
                            ),
                        ],
                        [
                             'type' => 'text',
                             'text' => $dependency->name,
                             'marks' => [['type' => 'strong']]
                        ],
                        [
                             'type' => 'text',
                             'text' => ' is outdated.'
                        ]
                    ]
                ],
                 [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Current version: ' . $dependency->currentVersion]
                    ]
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Latest version: ' . $dependency->latestVersion]
                    ]
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Please update the package and test accordingly.']
                    ]
                ]
            ]
        ];


        $payload = [
            'fields' => [
                'project' => [
                    'key' => $this->config['jira_project_key'],
                ],
                'issuetype' => [
                    'name' => $this->config['jira_issue_type'], // Ensure this matches an existing issue type name in your project
                ],
                'summary' => $summary,
                'description' => $descriptionPayload,
                 // Add Priority based on SemVer level
                 'priority' => [
                     'name' => $priorityName
                 ],
                 // Optional: Add labels
                 'labels' => [
                     'outdated-dependency',
                     $dependency->packageManager,
                     // $dependency->name // Maybe too noisy?
                 ]
            ],
             // Optional: Define how to handle updates if needed in the future
            // 'update' => []
        ];

        // Log attempt only when not dry-run and no existing ticket was found
        $this->logger->info('Attempting to create NEW JIRA ticket.', ['summary' => $summary]);

        try {
            $response = $this->httpClient->post('issue', [
                'json' => $payload,
            ]);

            // --- Simplified Diagnostics ---
            $statusCode = $response->getStatusCode();
            $bodyContent = '';
            try {
                $response->getBody()->rewind(); // Keep rewind just in case
                $bodyContent = $response->getBody()->getContents();
            } catch (\Exception $bodyReadException) {
                 $this->logger->warning('Exception reading JIRA response body', ['error' => $bodyReadException->getMessage()]);
                 // Removed echo
            }
            // --- End Simplified Diagnostics ---

            // Process the captured body content
            $responseData = json_decode($bodyContent, true);
            $jsonLastError = json_last_error();

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($jsonLastError !== JSON_ERROR_NONE) {
                    // Removed echo
                    // Log only a preview of the response body
                    $this->logger->error(
                        "JIRA ticket created (Status {$statusCode}) but failed to decode JSON response.",
                        [
                            'status' => $statusCode,
                            'response_preview' => substr($bodyContent, 0, 500),
                            'json_error' => json_last_error_msg()
                        ]
                    );
                    return null;
                }

                $issueKey = $responseData['key'] ?? null;
                if ($issueKey) {
                     $this->logger->info(
                         'Successfully created JIRA ticket.',
                         [
                            'key' => $issueKey,
                            'summary' => $summary
                         ]
                     );
                     return $issueKey;
                } else {
                     // Removed echo
                     $this->logger->error(
                         'JIRA ticket created but key not found in response.',
                         [
                           'status' => $statusCode,
                           'response_preview' => substr($bodyContent, 0, 500) // Use preview
                         ]
                     );
                     return null;
                }
            } else {
                 // Removed echo
                 // Removed echo
                 // Removed echo comment
                 $this->logger->error(
                     'Failed to create JIRA ticket.',
                     [
                        'status' => $statusCode,
                        'response_preview' => substr($bodyContent, 0, 500), // Use preview
                        // 'request_payload' => $payload // Comment out payload logging for brevity/security
                     ]
                 ); // phpcs:ignore Generic.Files.LineLength.TooLong
                return null;
            }
        } catch (RequestException $e) {
             // Removed echo
             // Removed echo block
             $this->logger->error('HTTP Request Exception during JIRA ticket creation.', [
                'message' => $e->getMessage(),
                'request' => $e->getRequest() ? \GuzzleHttp\Psr7\Message::toString($e->getRequest()) : 'N/A',
                'response' => $e->hasResponse() ? \GuzzleHttp\Psr7\Message::toString($e->getResponse()) : 'N/A',
             ]);
            return null;
        } catch (\Exception $e) {
            // Add echo for generic Exception
            echo "[DIAGNOSTIC] Generic Exception: " . $e->getMessage() . PHP_EOL;
            $this->logger->error(
                'Generic Exception during JIRA ticket creation.',
                ['message' => $e->getMessage()]
            );
            return null;
        }
    }

     // --- Optional: Duplicate Checking ---
    public function findExistingTicket(Dependency $dependency): ?string
    {
        // echo "[DIAGNOSTIC] Entering findExistingTicket for: {$dependency->name}" . PHP_EOL; // REMOVE
        // Construct the exact summary we would use for a new ticket
        $summary = sprintf(
            'Update %s package %s from %s to %s',
            ucfirst($dependency->packageManager),
            $dependency->name,
            $dependency->currentVersion,
            $dependency->latestVersion
        ); // phpcs:ignore Generic.Files.LineLength.TooLong

        // JQL requires quotes within the string to be escaped with a backslash
        $escapedSummary = str_replace('"', '\\"', $summary);

        // Using fuzzy match `~` which is generally more robust for text fields
        $jql = sprintf(
            'project = "%s" AND summary ~ "%s" AND statusCategory NOT IN ("Done", "Resolved") ORDER BY created DESC',
            $this->config['jira_project_key'],
            $escapedSummary // Use the escaped summary
        );

        // echo "[DIAGNOSTIC] About to enter search try block for: {$dependency->name}" . PHP_EOL; // REMOVE
        try {
            $response = $this->httpClient->get('search', [
                'query' => ['jql' => $jql, 'fields' => 'key,summary', 'maxResults' => 5] // Fetch summary, check a few results
            ]);

            $statusCode = $response->getStatusCode();
            // Rewind the stream before reading contents, as logger might have read it
            $response->getBody()->rewind();
            $body = $response->getBody()->getContents();

            if ($statusCode === 200) {
                $responseData = json_decode($body, true);
                // Check if decode failed
                if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error(
                        'Failed to decode JIRA search response JSON.',
                        [
                            'json_error' => json_last_error_msg(),
                            'response_body_preview' => substr($body, 0, 500)
                        ]
                    );
                    return null; // Treat decode failure as no duplicate found
                }

                $this->logger->debug(
                    'JIRA Search API Response (200 OK)',
                    [
                        'jql' => $jql,
                        // 'response_data' => $responseData // Might be too verbose for debug?
                    ]
                );

                // Check if total > 0 and issues exist
                // phpcs:ignore Generic.Files.LineLength.TooLong
                if (isset($responseData['total']) && $responseData['total'] > 0 && isset($responseData['issues']) && is_array($responseData['issues'])) {
                    // Iterate through returned issues and check for EXACT summary match
                    foreach ($responseData['issues'] as $issue) { // phpcs:ignore Generic.Files.LineLength.TooLong
                        if (isset($issue['fields']['summary']) && $issue['fields']['summary'] === $summary) {
                            $foundKey = $issue['key'];
                            $this->logger->debug(
                                'Found existing open JIRA ticket via search with exact summary match.',
                                ['key' => $foundKey]
                            );
                            return $foundKey;
                        }
                    }
                    // If loop completes without finding an exact match
                    $this->logger->debug(
                        'Search returned issues, but none had an exact summary match.',
                        ['expected_summary' => $summary]
                    ); // phpcs:ignore Generic.Files.LineLength.TooLong
                    return null;
                }
                // If total is 0 or issues array is missing/invalid
                // Restore original log message
                $this->logger->debug('No existing open ticket found via search (total=0 or issues array empty/invalid).');
                return null;
            } else {
                // Ensure diagnostic echo for search failure is present
                // echo "[DIAGNOSTIC] JIRA Search Failed - Status Code: {$statusCode}" . PHP_EOL; // REMOVE
                // echo "[DIAGNOSTIC] JIRA Search Failed - Response Body: {$body}" . PHP_EOL; // REMOVE
                // echo "[DIAGNOSTIC] JIRA Search Failed - JQL Used: {$jql}" . PHP_EOL; // REMOVE
                $this->logger->warning(
                    'JIRA API search for duplicates failed.',
                    [
                        'status' => $statusCode,
                        'response_preview' => substr($body, 0, 500), // Use preview
                        'jql' => $jql
                    ]
                ); // phpcs:ignore Generic.Files.LineLength.TooLong
                return null; // Proceed with creation if search fails?
            }
        } catch (RequestException | \Exception $e) {
             // Ensure diagnostic echo for search exception is present
             // echo "[DIAGNOSTIC] JIRA Search Exception: " . $e->getMessage() . PHP_EOL; // REMOVE
             // echo "[DIAGNOSTIC] JIRA Search Exception - JQL Used: {$jql}" . PHP_EOL; // REMOVE
             $this->logger->error(
                 'Exception during JIRA ticket search for duplicates.',
                 [
                    'message' => $e->getMessage(),
                    'jql' => $jql
                 ]
             ); // phpcs:ignore Generic.Files.LineLength.TooLong
             return null; // Proceed with creation on error?
        }
    }

     // Method to determine SemVer level difference
    private function getSemVerLevel(string $currentVersion, string $latestVersion): string
    {
        $normalize = fn($v) => ltrim(strtok($v, '-'), 'v'); // Remove 'v' prefix and suffixes like -beta

        $currentNormalized = $normalize($currentVersion);
        $latestNormalized = $normalize($latestVersion);

        // Basic check for semantic versioning format (X.Y.Z)
        if (!preg_match('/^\d+\.\d+\.\d+$/', $currentNormalized) || !preg_match('/^\d+\.\d+\.\d+$/', $latestNormalized)) {
             // If not standard X.Y.Z, use version_compare for basic comparison
             return version_compare($latestNormalized, $currentNormalized) > 0 ? 'UNKNOWN' : 'UNKNOWN'; // phpcs:ignore Generic.Files.LineLength.TooLong
        }

        $currentParts = explode('.', $currentNormalized);
        $latestParts = explode('.', $latestNormalized);

        // Use version_compare for robust comparison
        if (version_compare($latestNormalized, $currentNormalized, '<=')) {
            return 'UNKNOWN'; // latest is not newer or is same
        }

        if ($latestParts[0] !== $currentParts[0]) { // phpcs:ignore Generic.Files.LineLength.TooLong
            return 'MAJOR';
        }
        if ($latestParts[1] !== $currentParts[1]) {
            return 'MINOR';
        }
        // version_compare already confirmed latest > current, and major/minor are same,
        // so it must be patch or pre-release difference handled by normalize
        return 'PATCH';
    }
}
