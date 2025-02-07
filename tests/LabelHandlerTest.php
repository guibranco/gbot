<?php

use PHPUnit\Framework\TestCase;
use Src\LabelHandler;

class LabelHandlerTest extends TestCase
{
    private $githubClientMock;
    private $labelHandler;

    protected function setUp(): void
    {
        $this->githubClientMock = $this->createMock(GitHubClient::class);
        $this->labelHandler = new LabelHandler($this->githubClientMock);
    }

    public function testDetectMissingLabels()
    {
        $commentBody = "This is a comment with a label: bug and another label: enhancement.";
        $expectedLabels = ['bug', 'enhancement'];

        $detectedLabels = $this->labelHandler->detectMissingLabels($commentBody);

        $this->assertEquals($expectedLabels, $detectedLabels);
    }

    public function testHandleInvalidLabels()
    {
        $commentBody = "This is a comment with a label: bug.";
        $issueOrPrNumber = 123;
        $repository = 'owner/repo';

        $this->githubClientMock->expects($this->once())
            ->method('createLabel')
            ->with($repository, 'bug', 'f29513', 'Automatically created label');

        $this->githubClientMock->expects($this->once())
            ->method('assignLabel')
            ->with($repository, $issueOrPrNumber, 'bug');

        $this->labelHandler->handleInvalidLabels($commentBody, $issueOrPrNumber, $repository);
    }
}
