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
    private bool $lastSearchFailed = false;
    /**
     * Cache of summary => issue key to avoid duplicate creations within the same run
     * when Jira search indexing has not yet caught up.
     *
     * @var array<string, string|null>
     */
    private array $summaryTicketCache = [];

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
        $summary = $this->buildSummary($dependency);

        if (array_key_exists($summary, $this->summaryTicketCache)) {
            $this->logger->debug('Summary found in ticket cache, skipping JIRA search.', ['summary' => $summary]);
            return $this->summaryTicketCache[$summary];
        }

        // --- Check for existing duplicate ticket ---
        $existingKey = $this->findExistingTicket($dependency);
        if ($existingKey !== null) {
            $this->summaryTicketCache[$summary] = $existingKey;
            $this->logger->info(
                'Skipping creation: Found existing open ticket.',
                [
                    'key' => $existingKey,
                    'dependency' => $dependency->name
                ]
            ); // phpcs:ignore Generic.Files.LineLength.TooLong
            return $existingKey;
        }
        if ($this->lastSearchFailed) {
            $this->logger->warning(
                'Skipping creation: Unable to verify duplicates due to JIRA search failure.',
                [
                    'dependency' => $dependency->name,
                    'summary' => $summary,
                ]
            );
            return null;
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
                     $this->summaryTicketCache[$summary] = $issueKey;
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
        $this->lastSearchFailed = false;
        $summary = $this->buildSummary($dependency);
        $normalizedSummary = $this->normalizeSummary($summary);

        $jqlParts = [
            sprintf('project = "%s"', $this->config['jira_project_key']),
            sprintf('summary ~ "\\"%s\\""', $this->escapeJqlPhrase($summary)),
        ];
        $jql = implode(' AND ', $jqlParts) . ' ORDER BY created DESC';

        $this->logger->info(
            'Searching for existing ticket with JQL parts',
            [
                'dependency' => $dependency->name,
                'current' => $dependency->currentVersion,
                'latest' => $dependency->latestVersion,
                'summary' => $summary,
                'normalized_summary' => $normalizedSummary,
                'jql_parts' => $jqlParts,
                'jql' => $jql,
            ]
        );

        try {
            // JIRA API v3: /search/jql endpoint, GET with query parameters
            $response = $this->httpClient->get('search/jql', [
                'query' => [
                    'jql' => $jql,
                    'fields' => 'summary',
                    'maxResults' => 50,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $response->getBody()->rewind();
            $body = $response->getBody()->getContents();

            if ($statusCode === 200) {
                $responseData = json_decode($body, true);
                if ($responseData === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->lastSearchFailed = true;
                    $this->logger->error(
                        'Failed to decode JIRA search response JSON.',
                        [
                            'json_error' => json_last_error_msg(),
                            'response_body_preview' => substr($body, 0, 500)
                        ]
                    );
                    return null;
                }

                $this->logger->debug(
                    'JIRA Search API Response (200 OK)',
                    [
                        'jql' => $jql,
                        'total' => $responseData['total'] ?? null,
                        'returned_issues' => is_array($responseData['issues'] ?? null) ? count($responseData['issues']) : 0,
                    ]
                );

                if (isset($responseData['issues']) && is_array($responseData['issues'])) {
                    foreach ($responseData['issues'] as $issue) {
                        $fields = $issue['fields'] ?? [];
                        $issueSummary = $fields['summary'] ?? '';
                        if ($this->normalizeSummary($issueSummary) !== $normalizedSummary) {
                            continue;
                        }
                        $this->logger->debug(
                            'Found existing JIRA ticket via search with exact summary match.',
                            [
                                'key' => $issue['key'] ?? 'UNKNOWN',
                                'issue_summary' => $issueSummary,
                                'normalized_issue_summary' => $this->normalizeSummary($issueSummary),
                                'target_normalized_summary' => $normalizedSummary
                            ]
                        );
                        $foundKey = $issue['key'];
                        $this->logger->debug(
                            'Found existing ticket with an exact summary match.',
                            ['key' => $foundKey]
                        );
                        return $foundKey;
                    }
                    $this->logger->debug(
                        'No existing ticket with an exact summary match was found.',
                        ['expected_summary' => $summary]
                    );
                    return null;
                }
                $this->logger->debug('No existing open ticket found via search (issues array empty/invalid).');
                return null;
            } else {
                $this->lastSearchFailed = true;
                $this->logger->warning(
                    'JIRA API search for duplicates failed.',
                    [
                        'status' => $statusCode,
                        'response_preview' => substr($body, 0, 500),
                        'jql' => $jql
                    ]
                );
                return null;
            }
        } catch (RequestException | \Exception $e) {
            $this->lastSearchFailed = true;
            $this->logger->error(
                'Exception during JIRA ticket search for duplicates.',
                [
                    'message' => $e->getMessage(),
                    'jql' => $jql
                ]
            );
            return null;
        }
    }

    /**
     * Escapes Lucene special characters so the summary can be used in a quoted JQL text search.
     */
    private function escapeJqlPhrase(string $value): string
    {
        $escaped = preg_replace_callback(
            '/([+\-!(){}\[\]^"~*?:\\\\\\/&|])/',
            static fn(array $matches): string => '\\' . $matches[0],
            $value
        );

        return $escaped ?? $value;
    }

    private function buildSummary(Dependency $dependency): string
    {
        return sprintf(
            'Update %s package %s from %s to %s',
            ucfirst($dependency->packageManager),
            $dependency->name,
            $dependency->currentVersion,
            $dependency->latestVersion
        );
    }

    /**
     * Normalizes a summary string for safe equality checks.
     */
    private function normalizeSummary(string $summary): string
    {
        $normalized = strtolower($summary);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized);

        return trim(preg_replace('/\\s+/', ' ', $normalized ?? $summary));
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
