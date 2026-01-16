<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\JiraService;
use App\ValueObject\Dependency;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

class JiraServiceTest extends TestCase
{
    private MockHandler $mockHandler;
    private HttpClient $mockHttpClient;
    private LoggerInterface $logger;
    private array $baseConfig;
    /** @var array<int, array{request:\Psr\Http\Message\RequestInterface,response:\Psr\Http\Message\ResponseInterface,options:array}> */
    private array $historyContainer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock handler for Guzzle
        $this->mockHandler = new MockHandler();
        $this->historyContainer = [];
        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->historyContainer));
        $this->mockHttpClient = new HttpClient(['handler' => $handlerStack]);

        // Use NullLogger or a spy logger if needed
        $this->logger = new NullLogger();

        // Base configuration for tests
        $this->baseConfig = [
            'jira_url' => 'https://test-jira.example.com',
            'jira_user_email' => 'test@example.com',
            'jira_api_token' => 'test-token',
            'jira_project_key' => 'TEST',
            'jira_issue_type' => 'Task',
            'dry_run' => false,
        ];
    }

    private function createService(array $configOverrides = []): JiraService
    {
        $config = array_merge($this->baseConfig, $configOverrides);
        // Pass the mocked HttpClient and Logger to the service
        return new JiraService($config, $this->logger, $this->mockHttpClient);
    }

    private function escapeJqlPhrase(string $value): string
    {
        $escaped = preg_replace_callback(
            '/([+\-!(){}\[\]^"~*?:\\\\\\/&|])/',
            static fn(array $matches): string => '\\' . $matches[0],
            $value
        );

        return $escaped ?? $value;
    }

    // --- findExistingTicket Tests ---

    public function testFindExistingTicketSuccess(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $expectedSummary = 'Update Composer package test/package from 1.0.0 to 1.1.0';

        // Mock the JIRA search API response
        $mockResponseJson = json_encode([
            'issues' => [
                [
                    'key' => 'TEST-123',
                    'fields' => [
                        'summary' => $expectedSummary // Exact match
                    ]
                ]
            ]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockResponseJson));

        $result = $service->findExistingTicket($dependency);

        $this->assertEquals('TEST-123', $result);
    }

    public function testFindExistingTicketNoExactMatchSummary(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $expectedSummary = 'Update Composer package test/package from 1.0.0 to 1.1.0';

        // Mock response with a similar, but not exact, summary
        $mockResponseJson = json_encode([
            'issues' => [
                [
                    'key' => 'TEST-124',
                    'fields' => [
                        'summary' => 'Update test/package to 1.1.0' // Different summary
                    ]
                ]
            ]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockResponseJson));

        $result = $service->findExistingTicket($dependency);

        $this->assertNull($result);
    }

    public function testFindExistingTicketEscapesSpecialCharactersInSummary(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('@scope/package/name', '2.2.17', '2.2.18', 'npm');
        $expectedSummary = 'Update Npm package @scope/package/name from 2.2.17 to 2.2.18';

        $mockResponseJson = json_encode([
            'issues' => [
                [
                    'key' => 'TEST-777',
                    'fields' => [
                        'summary' => $expectedSummary
                    ]
                ]
            ]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockResponseJson));

        $service->findExistingTicket($dependency);

        $this->assertNotEmpty($this->historyContainer);
        /** @var array{request:\Psr\Http\Message\RequestInterface,response:\Psr\Http\Message\ResponseInterface,options:array} $lastRequestData */
        $lastRequestData = end($this->historyContainer);
        $lastRequest = $lastRequestData['request'];

        // For GET /search/jql, the JQL is in the query string.
        $queryString = $lastRequest->getUri()->getQuery();
        $this->assertNotSame('', $queryString, 'Expected query string to be present in request.');
        parse_str($queryString, $queryParams);
        $jqlQuery = $queryParams['jql'] ?? '';
        $this->assertNotSame('', $jqlQuery, 'Expected JQL query to be present in request.');
        $this->assertStringContainsString('summary ~ "\"' . $this->escapeJqlPhrase($expectedSummary) . '\""', $jqlQuery);
    }

    public function testFindExistingTicketMatchesNormalizedSummary(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $expectedSummary = 'Update Composer package test/package from 1.0.0 to 1.1.0';

        $mockResponseJson = json_encode([
            'issues' => [
                [
                    'key' => 'TEST-888',
                    'fields' => [
                        'summary' => ' Update   Composer  package  test/package   from  1.0.0  to  1.1.0  '
                    ]
                ]
            ]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockResponseJson));

        $result = $service->findExistingTicket($dependency);

        $this->assertSame('TEST-888', $result);
    }

    public function testFindExistingTicketNoResults(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock response with no issues
        $mockResponseJson = json_encode(['issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockResponseJson));

        $result = $service->findExistingTicket($dependency);

        $this->assertNull($result);
    }

    public function testFindExistingTicketApiError(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock a non-200 response
        $this->mockHandler->append(new Response(500, [], 'Internal Server Error'));

        $result = $service->findExistingTicket($dependency);

        $this->assertNull($result);
    }

    public function testFindExistingTicketRequestException(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock Guzzle throwing an exception
        $request = new Request('GET', 'test');
        $this->mockHandler->append(new RequestException('Connection error', $request));

        $result = $service->findExistingTicket($dependency);

        $this->assertNull($result);
    }

    // --- createTicket Tests ---

    public function testCreateTicketSkipsWhenExistingFound(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $existingKey = 'TEST-555';

        // Mock findExistingTicket response (simulate search finding an exact match)
        $mockSearchResponse = json_encode([
            'issues' => [['key' => $existingKey, 'fields' => ['summary' => 'Update Composer package test/package from 1.0.0 to 1.1.0']]]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // No need to mock POST response as it shouldn't be called

        $result = $service->createTicket($dependency);
        $this->assertEquals($existingKey, $result);
    }

    public function testCreateTicketSkipsWhenSearchFails(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        $this->mockHandler->append(new Response(410, [], '{"errorMessages":["Removed"],"errors":{}}'));

        $result = $service->createTicket($dependency);
        $this->assertNull($result);

        $this->assertCount(1, $this->historyContainer);
        $lastRequest = $this->historyContainer[0]['request'];
        $this->assertSame('GET', $lastRequest->getMethod());
    }

    public function testCreateTicketDryRunWouldCreate(): void
    {
        // Enable dry run
        $service = $this->createService(['dry_run' => true]);
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock findExistingTicket response (simulate search finding nothing)
        $mockSearchResponse = json_encode(['issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // No POST should occur in dry run

        $result = $service->createTicket($dependency);
        $this->assertEquals(JiraService::DRY_RUN_WOULD_CREATE, $result);
    }

    public function testCreateTicketDryRunExistingFound(): void
    {
        // Enable dry run
        $service = $this->createService(['dry_run' => true]);
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $existingKey = 'TEST-556';

        // Mock findExistingTicket response (simulate search finding an exact match)
         $mockSearchResponse = json_encode([
            'issues' => [['key' => $existingKey, 'fields' => ['summary' => 'Update Composer package test/package from 1.0.0 to 1.1.0']]]
         ]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // No POST should occur in dry run

        $result = $service->createTicket($dependency);
        $this->assertEquals($existingKey, $result);
    }

    public function testCreateTicketSuccess(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '2.1.0', 'composer'); // MAJOR update
        $newKey = 'TEST-557';

        // Expected summary (simple format)
        $expectedSummary = sprintf(
            'Update %s package %s from %s to %s',
            ucfirst($dependency->packageManager),
            $dependency->name,
            $dependency->currentVersion,
            $dependency->latestVersion
        );
        // Define expected priority for MAJOR update (CHANGED)
        $expectedPriority = 'Emergency';

        // Mock findExistingTicket finding nothing
        $mockSearchResponse = json_encode(['issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // Mock successful POST response
        $mockCreateResponse = json_encode(['key' => $newKey, 'id' => '12345', 'self' => '...']);

        // Use callable to assert the request payload includes priority
        // phpcs:ignore Generic.Files.LineLength.TooLong
        $this->mockHandler->append(function (Request $request, array $options) use ($mockCreateResponse, $expectedSummary, $expectedPriority) {
            $body = $request->getBody()->getContents();
            $payload = json_decode($body, true);

            $this->assertNotNull($payload);
            $this->assertEquals($expectedSummary, $payload['fields']['summary'] ?? null);
            // Assert the priority field (CHANGED)
            $this->assertEquals($expectedPriority, $payload['fields']['priority']['name'] ?? null);
            // Optionally add back description checks if needed
            // $descriptionText = json_encode($payload['fields']['description'] ?? []);
            // $this->assertStringContainsString(...);

            return new Response(201, [], $mockCreateResponse);
        });

        $result = $service->createTicket($dependency);
        $this->assertEquals($newKey, $result);
    }

    public function testCreateTicketUsesSummaryCacheToAvoidDuplicateCreationInSameRun(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '2.0.0', 'composer');

        $mockSearchResponse = json_encode(['issues' => []]);
        $mockCreateResponse = json_encode(['key' => 'TEST-600']);

        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));
        $this->mockHandler->append(new Response(201, [], $mockCreateResponse));

        $firstResult = $service->createTicket($dependency);
        $this->assertSame('TEST-600', $firstResult);

        $secondResult = $service->createTicket($dependency);
        $this->assertSame('TEST-600', $secondResult);
    }

    public function testCreateTicketApiError(): void
    {
        $service = $this->createService(); // Not dry run
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock findExistingTicket finding nothing
        $mockSearchResponse = json_encode(['issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // Mock failed POST response
        $this->mockHandler->append(new Response(400, [], 'Bad Request'));

        $result = $service->createTicket($dependency);
        $this->assertNull($result);
    }

    public function testCreateTicketRequestException(): void
    {
        $service = $this->createService(); // Not dry run
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock findExistingTicket finding nothing
        $mockSearchResponse = json_encode(['total' => 0, 'issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // Mock Guzzle throwing an exception on POST
        $request = new Request('POST', 'test');
        $this->mockHandler->append(new RequestException('Network error', $request));

        $result = $service->createTicket($dependency);
        $this->assertNull($result);
    }

    // --- createTicketsForDependencies Tests ---

    /*
    public function testPlaceholder(): void
    {
        $this->assertTrue(true);
    }
    */
}
