<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\MarkdownGroupCheckboxValidator;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Library\PullRequestCodeScanner;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

function handleItem($pullRequest, $isRetry = false)
{
    if (!$isRetry) {
        echo "https://github.com/{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}/pull/{$pullRequest->Number}:\n\n";
    }

    $config = loadConfig();
    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $metadata = createMetadata($token, $pullRequest, $config);
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    if ($pullRequestUpdated->state === "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
        removeLabels($metadata, $pullRequestUpdated);
        checkForOtherPullRequests($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        echo "PR State: {$pullRequestUpdated->state} ⛔\n";
        return;
    }

    if ($isRetry === false && $pullRequestUpdated->mergeable_state === "unknown") {
        sleep(5);
        echo "State: {$pullRequestUpdated->mergeable_state} - Retrying #{$pullRequestUpdated->number} - Sender: " . $pullRequest->Sender . " 🔄\n";
        handleItem($pullRequest, true);
        return;
    }

    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
    enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
    addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated);
    updateBranch($metadata, $pullRequestUpdated);

    $labelsToAdd = [];
    if (strtolower($pullRequestUpdated->user->type) === "bot") {
        $labelsToAdd[] = "🤖 bot";
    }

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->body), "login");

    $botReviewed = false;
    $invokerReviewed = false;

    $reviewsLogins = getReviewsLogins($metadata);
    if (in_array($config->botName . "[bot]", $reviewsLogins)) {
        $botReviewed = true;
    }

    $intersections = array_intersect($reviewsLogins, $collaboratorsLogins);

    if (count($intersections) > 0) {
        $invokerReviewed = true;
    }

    if ($pullRequestUpdated->assignee == null) {
        $body = array("assignees" => $collaboratorsLogins);
        doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
    }

    if (!$botReviewed) {
        $body = array("event" => "APPROVE");
        doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], $body, "POST");
    }

    $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters);
    $iAmTheOwner = in_array($config->ownerHandler, $collaboratorsLogins);

    if (!$invokerReviewed && $autoReview && $iAmTheOwner) {
        $bodyMsg = "Automatically approved by " . $metadata["botNameMarkdown"];
        $body = array("event" => "APPROVE", "body" => $bodyMsg);
        doRequestGitHub($metadata["userToken"], $metadata["reviewsUrl"], $body, "POST");
    }

    if (!$invokerReviewed && !$autoReview) {
        $reviewers = $collaboratorsLogins;
        if (in_array($pullRequest->Sender, $reviewers)) {
            $reviewers = array_values(array_diff($reviewers, array($pullRequest->Sender)));
        }
        if (count($reviewers) > 0) {
            $body = array("reviewers" => $reviewers);
            doRequestGitHub($metadata["token"], $metadata["requestReviewUrl"], $body, "POST");
        }

        if (!in_array($pullRequest->Sender, $collaboratorsLogins)) {
            $labelsToAdd[] = "🚦 awaiting triage";
        }
    }

    if (count($labelsToAdd) > 0) {
        $body = array("labels" => $labelsToAdd);
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
    }

    if ($iAmTheOwner) {
        resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
        handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins);
    }

    checkPullRequestDescription($metadata, $pullRequestUpdated);
    checkPullRequestContent($metadata, $pullRequestUpdated);
    setCheckRunSucceeded($metadata, $checkRunId, "pull request");
}

function createMetadata($token, $pullRequest, $config)
{
    global $gitHubUserToken;

    $prQueryString =
        "pull-requests/?owner=" . $pullRequest->RepositoryOwner .
        "&repo=" . $pullRequest->RepositoryName .
        "&pullRequest=" . $pullRequest->Number;
    $repoPrefix = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName;
    return array(
        "token" => $token,
        "userToken" => $gitHubUserToken,
        "squashAndMergeComment" => "@dependabot squash and merge",
        "mergeComment" => "@depfu merge",
        "commentsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/comments",
        "pullRequestUrl" => $repoPrefix . PULLS . $pullRequest->Number,
        "pullRequestsUrl" => $repoPrefix . "/pulls",
        "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
        "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "issuesUrl" => $repoPrefix . "/issues",
        "labelsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/labels",
        "compareUrl" => $repoPrefix . "/compare/",
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $config->dashboardUrl . $prQueryString
    );
}

function checkPullRequestDescription($metadata, $pullRequestUpdated)
{
    $type = "pull request description";
    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);
    $bodyLength = isset($pullRequestUpdated->body) ? strlen($pullRequestUpdated->body) : 0;
    if ($bodyLength === 0) {
        $templateContent = getPullRequestTemplate($metadata);
        if ($templateContent) {
            updatePullRequestDescription($metadata, $templateContent);
            // The check should ask to review the PR description (if there are checkbox on it, set is as failed, otherwise, set it as succeeded).
            return;
        }

        $defaultMessage = "Please provide a description for this pull request.";
        updatePullRequestDescription($metadata, $defaultMessage);
        // add a comment with the same content.
    }

    if ($bodyLength <= 250) {
        setCheckRunFailed($metadata, $checkRunId, $type, "Pull request description too short (at least 250 characters long).");
        return;
    }

    $validator = new MarkdownGroupCheckboxValidator();
    $validationResult = $validator->validateCheckboxes($pullRequestUpdated->body);
    if (isset($validationResult['errors']) && !empty($validationResult['errors'])) {
        $message = $validator->generateReport($validationResult);
        setCheckRunFailed($metadata, $checkRunId, $type, $message);
        return;
    } elseif ($validationResult["found"] === false || $validationResult["found"] === 0) {
        setCheckRunSucceeded($metadata, $checkRunId, $type, "No groups or checkboxes found in the PR body.");
        return;
    }

    setCheckRunSucceeded($metadata, $checkRunId, $type);
}

function checkPullRequestContent($metadata, $pullRequestUpdated)
{
    $type = "pull request content";
    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);
    $diffResponse = getPullRequestDiff($metadata);
    $diff = $diffResponse->body;
    $scanner = new PullRequestCodeScanner();
    $comments = $scanner->scanDiffForKeywords($diff);
    $report = $scanner->generateReport($comments);
    if (!empty($comments)) {
        setCheckRunFailed($metadata, $checkRunId, $type, $report);
        return;
    }

    setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
}

function removeLabels($metadata, $pullRequestUpdated)
{
    $labelsLookup = [
        "🚦 awaiting triage",
        "⏳ awaiting response",
        "🛠 WIP"
    ];

    $labels = array_column($pullRequestUpdated->labels, "name");
    $intersect = array_intersect($labelsLookup, $labels);

    foreach ($intersect as $label) {
        $label = str_replace(" ", "%20", $label);
        $url = $metadata["pullRequestUrl"] . "/labels/{$label}";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
    }
}

function checkForOtherPullRequests($metadata, $pullRequest)
{
    $pullRequestsOpenResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestsUrl"] . "?state=open&sort=created", null, "GET");
    $pullRequestsOpen = json_decode($pullRequestsOpenResponse->body);
    $any = false;

    if (count($pullRequestsOpen) === 0) {
        echo "No other pull requests to review ❌\n";
        return;
    }

    foreach ($pullRequestsOpen as $pullRequestPending) {
        if ($pullRequest->Number === $pullRequestPending->number) {
            continue;
        }
        if ($pullRequestPending->auto_merge !== null) {
            triggerReview($pullRequest, $pullRequestPending);
            $any = true;
            break;
        }
    }

    if ($any) {
        return;
    }

    foreach ($pullRequestsOpen as $pullRequestPending) {
        if ($pullRequest->Number === $pullRequestPending->number) {
            continue;
        }

        triggerReview($pullRequest, $pullRequestPending);
        break;
    }
}

function triggerReview($pullRequest, $pullRequestPending)
{
    $prUpsert = new \stdClass();
    $prUpsert->DeliveryId = $pullRequest->DeliveryIdText;
    $prUpsert->HookId = $pullRequest->HookId;
    $prUpsert->TargetId = $pullRequest->TargetId;
    $prUpsert->TargetType = $pullRequest->TargetType;
    $prUpsert->RepositoryOwner = $pullRequest->RepositoryOwner;
    $prUpsert->RepositoryName = $pullRequest->RepositoryName;
    $prUpsert->Id = $pullRequestPending->id;
    $prUpsert->Sender = $pullRequestPending->user->login;
    $prUpsert->Number = $pullRequestPending->number;
    $prUpsert->NodeId = $pullRequestPending->node_id;
    $prUpsert->Title = $pullRequestPending->title;
    $prUpsert->Ref = $pullRequestPending->head->ref;
    $prUpsert->InstallationId = $pullRequest->InstallationId;
    echo "Triggering review of #{$pullRequestPending->number} - Sender: " . $pullRequest->Sender . " 🔄\n";
    upsertPullRequest($prUpsert);
}

function handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins)
{
    commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["squashAndMergeComment"], "dependabot[bot]");
    commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["mergeComment"], "depfu[bot]");
}


function commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $commentToLookup, $senderToLookup)
{
    if ($pullRequest->Sender != $senderToLookup) {
        return;
    }

    $commentsRequest = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], null, "GET");
    $comments = json_decode($commentsRequest->body);

    $found = false;

    foreach ($comments as $comment) {
        if (
            stripos($comment->body, $commentToLookup) !== false &&
            in_array($comment->user->login, $collaboratorsLogins)
        ) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        $comment = array("body" => $commentToLookup);
        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");

        $label = array("labels" => array("☑️ auto-merge"));
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
    }
}

function removeIssueWipLabel($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (
        !isset($referencedIssue->data->repository) ||
        count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
    ) {
        return;
    }

    foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
        $issueNumber = $node->number;
        $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

        $labels = array_column(json_decode($issueResponse->body)->labels, "name");

        if (in_array("🛠 WIP", $labels)) {
            $url = $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/🛠%20WIP";
            doRequestGitHub($metadata["token"], $url, null, "DELETE");
        }
    }
}

function getReviewsLogins($metadata)
{
    $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
    $reviews = json_decode($reviewsResponse->body);
    return array_map(function ($review) {
        return $review->user->login;
    }, $reviews);
}

function getReferencedIssue($metadata, $pullRequest)
{
    $referencedIssueQuery = array(
        "query" => "query {
        repository(owner: \"" . $pullRequest->RepositoryOwner . "\", name: \"" . $pullRequest->RepositoryName . "\") {
          pullRequest(number: " . $pullRequest->Number . ") {
            closingIssuesReferences(first: 10) {
              nodes {
                  number
              }
            }
          }
        }
      }"
    );

    $referencedIssueResponse = doRequestGitHub($metadata["token"], "graphql", $referencedIssueQuery, "POST");
    if ($referencedIssueResponse->statusCode >= 300) {
        return null;
    }
    return json_decode($referencedIssueResponse->body);
}

function addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (
        !isset($referencedIssue->data->repository) ||
        count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
    ) {
        return;
    }

    $labels = array();
    foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
        $issueNumber = $node->number;
        $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");
        $issue = json_decode($issueResponse->body);

        $labelsIssue = array_column($issue->labels, "name");
        $position = array_search("🛠 WIP", $labelsIssue);

        if ($position !== false) {
            unset($labelsIssue[$position]);
        } else {
            $body = array("labels" => array("🛠 WIP"));
            doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels", $body, "POST");
        }

        $labelsToNotCopy = ["🤖 bot", "good first issue", "help wanted"];

        foreach ($labelsToNotCopy as $label) {
            $position = array_search($label, $labelsIssue);

            if ($position !== false && strtolower($pullRequestUpdated->user->type) !== "bot") {
                unset($labelsIssue[$position]);
            }
        }

        $labels = array_merge($labels, $labelsIssue);
    }

    $body = array("labels" => array_values($labels));
    doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
}

function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
{
    $isSenderAutoMerge = in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters);
    if (
        $pullRequestUpdated->auto_merge == null &&
        $isSenderAutoMerge
    ) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        doRequestGitHub($metadata["userToken"], "graphql", $body, "POST");

        $label = array("labels" => array("☑️ auto-merge"));
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
    }

    if ($pullRequestUpdated->mergeable_state === "clean" && $pullRequestUpdated->mergeable) {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ✅\n";
        $comment = array("body" => "<!-- gstraccini-bot:ready-merge -->\nThis pull request is ready ✅ for merge/squash.");
        doRequestGitHub($metadata["token"], $metadata["commentsUrl"], $comment, "POST");
        //$body = array("merge_method" => "squash", "commit_title" => $pullRequest->Title);
        //requestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/merge", $body);
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is NOT mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ⛔\n";
    }
}

function resolveConflicts($metadata, $pullRequest, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state !== "clean" && $pullRequestUpdated->mergeable === false) {
        if ($pullRequest->Sender !== "dependabot[bot]" && $pullRequest->Sender !== "depfu[bot]") {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Conflicts - Sender: " . $pullRequest->Sender . " ⚠️\n";
            return;
        }
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Recreate via bot - Sender: " . $pullRequest->Sender . " ☢️\n";

        if ($pullRequest->Sender === "dependabot[bot]") {
            $comment = array("body" => "@dependabot recreate");
        } else {
            $comment = array("body" => "@depfu recreate");
        }

        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - No conflicts - Sender: " . $pullRequest->Sender . " 🆒\n";
    }
}

function updateBranch($metadata, $pullRequestUpdated)
{
    $baseRef = urlencode($pullRequestUpdated->base->ref);
    $headRef = urlencode($pullRequestUpdated->head->ref);

    $compareResponse = doRequestGitHub($metadata["token"], "{$metadata["compareUrl"]}{$baseRef}...{$headRef}", null, "GET");
    if ($compareResponse->statusCode >= 300) {
        return;
    }
    $compare = json_decode($compareResponse->body);

    if ($compare->behind_by === 0) {
        echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: 0 - Updating branch: No - Sender: {$pullRequestUpdated->user->login} 👎🏻\n";
        return;
    }

    echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: {$compare->behind_by} - Updating branch: Yes - Sender: {$pullRequestUpdated->user->login} 👍🏻\n";
    $url = $metadata["pullRequestUrl"] . "/update-branch";
    $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
    doRequestGitHub($metadata["token"], $url, $body, "PUT");
}

function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_pull_requests";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->pullRequests === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoPullRequests, GUIDv4::random());
$healthCheck->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
$healthCheck->start();
$time = time();
while (true) {
    main();
    $limit = ($time + 55);
    if ($limit < time()) {
        break;
    }
}
$healthCheck->end();
