<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\CommandExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class CommandExecutorTest extends TestCase
{
    private CommandExecutor $executor;
    private $mockProcessFactory;
    /** @var Process|\PHPUnit\Framework\MockObject\MockObject */
    private $mockProcess;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up the mock process object that the factory will return
        $this->mockProcess = $this->createMock(Process::class);

        // Create a factory closure that returns the mock process
        // We capture $this->mockProcess by reference using `use`
        $this->mockProcessFactory = function (array $command, string $cwd) {
             // Optionally: Add assertions here to check $command and $cwd if needed
             // $this->assertEquals(['ls', '-la'], $command); 
             // $this->assertEquals('/tmp', $cwd);
             return $this->mockProcess;
        };

        // Instantiate the executor with the mock factory
        $this->executor = new CommandExecutor(new NullLogger(), $this->mockProcessFactory);
    }

    public function testExecuteSuccess(): void
    {
        $command = ['test', 'command'];
        $cwd = '/test/dir';
        $expectedOutput = 'Command completed successfully';

        // Configure the mock Process behavior for success
        $this->mockProcess->expects($this->once())->method('run');
        $this->mockProcess->expects($this->once())
                          ->method('isSuccessful')
                          ->willReturn(true);
        $this->mockProcess->expects($this->once())
                          ->method('getOutput')
                          ->willReturn($expectedOutput);
        $this->mockProcess->expects($this->never())->method('getErrorOutput'); // Should not be called on success

        $actualOutput = $this->executor->execute($command, $cwd);

        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testExecuteFailureThrowsException(): void
    {
        $command = ['failing', 'command'];
        $cwd = '/another/dir';
        $errorOutput = 'Something went wrong';
        $exitCode = 1;

        // Configure the mock Process behavior for failure
        $this->mockProcess->expects($this->once())->method('run');
        $this->mockProcess->method('isSuccessful')
                          ->willReturn(false);
        $this->mockProcess->method('getErrorOutput')
                          ->willReturn($errorOutput);
        $this->mockProcess->method('getExitCode')->willReturn($exitCode);
        $this->mockProcess->method('getCommandLine')->willReturn(implode(' ', $command));
        $this->mockProcess->method('getWorkingDirectory')->willReturn($cwd);
        $this->mockProcess->method('getOutput')->willReturn('');

        // Assert that ProcessFailedException is thrown
        $this->expectException(ProcessFailedException::class);
        // Optionally: Check exception message or properties if needed
        // $this->expectExceptionMessage(...);

        $this->executor->execute($command, $cwd);
    }
} 