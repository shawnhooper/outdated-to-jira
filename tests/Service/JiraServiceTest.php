<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\JiraService;
use App\ValueObject\Dependency;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock handler for Guzzle
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
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

    // --- findExistingTicket Tests --- 

    public function testFindExistingTicketSuccess(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $expectedSummary = 'Update Composer package test/package from 1.0.0 to 1.1.0';

        // Mock the JIRA search API response
        $mockResponseJson = json_encode([
            'total' => 1,
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
            'total' => 1,
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

    public function testFindExistingTicketNoResults(): void
    {
        $service = $this->createService();
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock response with total = 0
        $mockResponseJson = json_encode(['total' => 0, 'issues' => []]);
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
            'total' => 1, 
            'issues' => [['key' => $existingKey, 'fields' => ['summary' => 'Update Composer package test/package from 1.0.0 to 1.1.0']]]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // No need to mock POST response as it shouldn't be called

        $result = $service->createTicket($dependency);
        $this->assertEquals($existingKey, $result);
    }

    public function testCreateTicketDryRunWouldCreate(): void
    {
        // Enable dry run
        $service = $this->createService(['dry_run' => true]);
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock findExistingTicket response (simulate search finding nothing)
        $mockSearchResponse = json_encode(['total' => 0, 'issues' => []]);
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
            'total' => 1, 
            'issues' => [['key' => $existingKey, 'fields' => ['summary' => 'Update Composer package test/package from 1.0.0 to 1.1.0']]]
        ]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // No POST should occur in dry run

        $result = $service->createTicket($dependency);
        $this->assertEquals($existingKey, $result);
    }

    public function testCreateTicketSuccess(): void
    {
        $service = $this->createService(); // Not dry run
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');
        $newKey = 'TEST-557';

        // Mock findExistingTicket finding nothing
        $mockSearchResponse = json_encode(['total' => 0, 'issues' => []]);
        $this->mockHandler->append(new Response(200, [], $mockSearchResponse));

        // Mock successful POST response for issue creation
        $mockCreateResponse = json_encode(['key' => $newKey, 'id' => '12345', 'self' => '...']);
        $this->mockHandler->append(new Response(201, [], $mockCreateResponse)); // 201 Created

        $result = $service->createTicket($dependency);
        $this->assertEquals($newKey, $result);
    }

    public function testCreateTicketApiError(): void
    {
        $service = $this->createService(); // Not dry run
        $dependency = new Dependency('test/package', '1.0.0', '1.1.0', 'composer');

        // Mock findExistingTicket finding nothing
        $mockSearchResponse = json_encode(['total' => 0, 'issues' => []]);
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