<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($issue)
{
    echo "https://github.com/{$issue->RepositoryOwner}/{$issue->RepositoryName}/issues/{$issue->Number}:\n\n";

    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $repoPrefix = "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName;
    $metadata = array(
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "assigneesUrl" => $repoPrefix . "/issues/" . $issue->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "issueUrl" => $repoPrefix . "/issues/" . $issue->Number,
    );

    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issueUrl"], null, "GET");
    $issueUpdated = json_decode($issueResponse->getBody());

    if ($issueUpdated->state === "closed") {
        removeLabels($issueUpdated, $metadata, true);
        if ($issue->State === "OPEN") {
            updateStateToClosedInTable("issues", $issue->Sequence);
        }

        return;
    }

    // Check for Acceptance Criteria
    if (!containsAcceptanceCriteria($issueUpdated->body)) {
        commentOnIssue($metadata, "Please add an **Acceptance Criteria** section with a checklist of tasks.");
    }
}

function containsAcceptanceCriteria($body)
{
    return preg_match('/##\s*Acceptance Criteria\s*\n(\s*- \[ \].+\n?)+/i', $body);

    if ($issueUpdated->assignee != null) {
        removeLabels($issueUpdated, $metadata);
        return;
    }

    $repositoryResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"], null, "GET");
    $repository = json_decode($repositoryResponse->getBody());

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaborators = json_decode($collaboratorsResponse->getBody(), true);
    $collaboratorsLogins = array_column($collaborators, "login");

    if ($repository->private) {
        $body = array("assignees" => $collaboratorsLogins);
        doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
        removeLabels($issueUpdated, $metadata);
        return;
    }

    addLabels($issueUpdated, $collaboratorsLogins, $metadata);

    if(in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        removeLabels($issueUpdated, $metadata);
    }
}

function addLabels($issueUpdated, $collaboratorsLogins, $metadata)
{
    $labels = [];
    if (!in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        $labels[] = "🚦 awaiting triage";
    }

    if ($issueUpdated->user->type === "Bot") {
        $labels[] = "🤖 bot";
    }

    if (count($labels) > 0) {
        $body = array("labels" => $labels);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
    }
}

function removeLabels($issueUpdated, $metadata, $includeWip = false)
{
    $labelsLookup = [
        "🚦 awaiting triage",
        "⏳ awaiting response"
    ];
    if ($includeWip === true) {
        $labelsLookup[] = "🛠 WIP";
    }

    $labels = array_column($issueUpdated->labels, "name");
    $intersect = array_intersect($labelsLookup, $labels);

    foreach ($intersect as $label) {
        $label = str_replace(" ", "%20", $label);
        $url = $metadata["issueUrl"] . "/labels/{$label}";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
    }
}

$healthCheck = new HealthChecks($healthChecksIoIssues, GUIDv4::random());
$processor = new ProcessingManager("issues", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
