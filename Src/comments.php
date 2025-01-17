<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\CommandHelper;
use GuiBranco\GStracciniBot\Library\CreateLabelResult;
use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\LabelService;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

/**
 * Converts a string to camel case format.
 *
 * @param string $inputString The input string to convert
 * @return string The camel case formatted string
 *
 * @throws InvalidArgumentException If the input is not a string
 */
function toCamelCase($inputString)
{
    if (!is_string($inputString)) {
        throw new \InvalidArgumentException('Input must be a string');
    }
    if (empty($inputString)) {
        return '';
    }
    return preg_replace_callback(
        '/(?:^|_| )(\w)/',
        function ($matches) {
            return strtoupper($matches[1]);
        },
        $inputString
    );
}

function handleItem($comment): void
{
    echo "https://github.com/{$comment->RepositoryOwner}/{$comment->RepositoryName}/issues/{$comment->PullRequestNumber}/#issuecomment-{$comment->CommentId}:\n\n";
    echo "Comment: {$comment->CommentBody} | Sender: {$comment->CommentSender}\n";

    $config = loadConfig();

    if ($comment->CommentSender === $config->botName . "[bot]") {
        return;
    }

    $prefix = "I'm sorry @" . $comment->CommentSender;
    $suffix = ", I can't do that.";
    $emoji = " :pleading_face:";

    $repoPrefix = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName;
    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "repoPrefix" => $repoPrefix,
        "repositoryOwner" => $comment->RepositoryOwner,
        "repositoryName" => $comment->RepositoryName,
        "reactionUrl" => $repoPrefix . "/issues/comments/" . $comment->CommentId . "/reactions",
        "pullRequestUrl" => $repoPrefix . "/pulls/" . $comment->PullRequestNumber,
        "issueUrl" => $repoPrefix . "/issues/" . $comment->PullRequestNumber,
        "commentUrl" => $repoPrefix . "/issues/" . $comment->PullRequestNumber . "/comments",
        "labelsUrl" => $repoPrefix . "/labels",
        "errorMessages" => array(
            "notCollaborator" => $prefix . $suffix . " You aren't a collaborator in this repository." . $emoji,
            "invalidParameter" => $prefix . $suffix . " Invalid parameter." . $emoji,
            "notOpen" => $prefix . $suffix . " This pull request is no longer open. :no_entry:",
            "notAllowed" => $prefix . $suffix . " You aren't allowed to use this bot." . $emoji,
            "commandNotFound" => $prefix . $suffix . " Command not found." . $emoji,
            "notImplemented" => $prefix . $suffix . " Feature not implemented yet." . $emoji
        )
    );

    if (
        $comment->CommentSender === "github-actions[bot]" ||
        $comment->CommentSender === "AppVeyorBot" ||
        $comment->CommentSender === "gitauto-ai[bot]"
    ) {
        echo "Skipping this comment! 🚷\n";
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        return;
    }

    $collaboratorUrl = $repoPrefix . "/collaborators/" . $comment->CommentSender;
    $collaboratorResponse = doRequestGitHub($metadata["token"], $collaboratorUrl, null, "GET");
    if ($collaboratorResponse->getStatusCode() === 404) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["notCollaborator"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $executedAtLeastOne = false;

    $pullRequestIsOpen = checkIfPullRequestIsOpen($metadata);

    foreach ($config->commands as $command) {
        $commandExpression = "@" . $config->botName . " " . $command->command;
        if (stripos($comment->CommentBody, $commandExpression) !== false) {
            $executedAtLeastOne = true;
            if (isset($command->requiresPullRequestOpen) && $command->requiresPullRequestOpen && !$pullRequestIsOpen) {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
                $body = $metadata["errorMessages"]["notOpen"];
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
                continue;
            }
            $method = "execute_" . toCamelCase($command->command);
            $method($config, $metadata, $comment);
        }
    }

    if (!$executedAtLeastOne) {
        $body = $metadata["errorMessages"]["commandNotFound"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
    }
}

function execute_hello($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"), "POST");
    $body = "Hello @" . $comment->CommentSender . "! :wave:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_thankYou($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");
    $body = "You're welcome @" . $comment->CommentSender . "! :pray:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_help($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $helpComment = "That's what I can do :neckbeard::\r\n";
    foreach ($config->commands as $command) {
        $parameters = "";
        $parametersHelp = "";
        $prefix = "[ ] ";
        $inDevelopment = isset($command->dev) && $command->dev
            ? " :warning: (In development, it may not work as expected!)"
            : "";
        if (isset($command->parameters)) {
            $prefix = "";
            foreach ($command->parameters as $parameter) {
                $parameters .= " <" . $parameter->parameter . ">";
                $parametersHelp .= "\t- `" . $parameter->parameter . "` - `[" .
                    ($parameter->required ? "required" : "optional") . "]` " .
                    $parameter->description . "\r\n";
            }
        }

        $helpComment .= "- {$prefix}`@{$config->botName} {$command->command}{$parameters}` - ";
        $helpComment .= $command->description . $inDevelopment . "\r\n";
        $helpComment .= $parametersHelp;
    }
    $helpComment .= "\n\nMultiple commands can be issued simultaneously. " .
        "Just respect each command pattern (with bot name prefix + command).\n\n" .
        "> [!NOTE]\n" .
        "> \n" .
        "> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n" .
        "\n\n" .
        "> [!TIP]\n" .
        "> \n" .
        "> You can tick (✅) one item from the above list, and it will be triggered! (In beta) (Only parameterless commands).\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment), "POST");
}

function execute_addProject($config, $metadata, $comment): void
{
    preg_match(
        "/@" . $config->botName . "\sadd\sproject\s(.+?\.csproj)/",
        $comment->CommentBody,
        $matches
    );

    if (count($matches) === 2) {
        $projectPath = $matches[1];
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
        $body = "Adding project `{$projectPath}` to solution! :wrench:";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        $parameters = array("projectPath" => $projectPath);
        callWorkflow($config, $metadata, $comment, "add-project-to-solution.yml", $parameters);
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }
}

function execute_appveyorBuild($config, $metadata, $comment): void
{
    preg_match(
        "/@" . $config->botName . "\sappveyor\sbuild(?:\s(commit|pull request))?/",
        $comment->CommentBody,
        $matches
    );

    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    $data = array(
        "accountName" => $project->accountName,
        "projectSlug" => $project->slug
    );

    if (count($matches) === 2 && $matches[1] === "commit") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["branch"] = $metadata["headRef"];
        $data["commitId"] = $metadata["headSha"];
    } elseif (count($matches) === 2 && $matches[1] === "pull request") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["pullRequestId"] = $comment->PullRequestNumber;
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $buildResponse = requestAppVeyor("builds", $data);
    if ($buildResponse->getStatusCode() !== 200) {
        $commentBody = "AppVeyor build failed: :x:\r\n\r\n```\r\n" . $buildResponse->getBody() . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }
    $build = json_decode($buildResponse->getBody());
    $buildId = $build->buildId;
    $version = $build->version;
    $link = "https://ci.appveyor.com/project/" . $project->accountName . "/" . $project->slug . "/builds/" . $buildId;
    $commentBody = "AppVeyor build (" . $matches[1] . ") started! :rocket:\r\n\r\n" .
        "Build ID: [" . $buildId . "](" . $link . ")\r\n" .
        "Version: " . $version . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorBumpVersion($config, $metadata, $comment): void
{
    preg_match(
        "/@" . $config->botName . "\sappveyor\sbump\sversion(?:\s(major|minor|build))?/",
        $comment->CommentBody,
        $matches
    );

    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    $url = "projects/" . $project->accountName . "/" . $project->slug . "/settings";
    $settingsResponse = requestAppVeyor($url);
    if ($settingsResponse->getStatusCode() !== 200) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $commentBody = "AppVeyor bump version failed: :x:\r\n\r\n```\r\n" . $settingsResponse->getBody() . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }

    $settings = json_decode($settingsResponse->getBody());

    if (count($matches) === 2 && $matches[1] === "build") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        updateNextBuildNumber($metadata, $project, $settings->settings->nextBuildNumber + 1);
    } elseif (count($matches) === 2 && ($matches[1] === "minor" || $matches[1] === "major")) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["notImplemented"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    }
}

function execute_appveyorRegister($config, $metadata, $comment): void
{
    $data = array(
        "repositoryProvider" => "gitHub",
        "repositoryName" => $comment->RepositoryOwner . "/" . $comment->RepositoryName,
    );
    $registerResponse = requestAppVeyor("projects", $data);
    if ($registerResponse->getStatusCode() !== 200) {
        $commentBody = "AppVeyor registration failed: :x:\r\n\r\n```\r\n" . $registerResponse->getBody() . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }
    $register = json_decode($registerResponse->getBody());

    $link = "https://ci.appveyor.com/project/" .
        $register->accountName . "/" . $register->slug;
    $commentBody = "AppVeyor registered! :rocket:\r\n\r\n" .
        "Project ID: [" . $register->projectId . "](" . $link . ")\r\n" .
        "Slug: " . $register->slug . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorReset($config, $metadata, $comment): void
{
    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    updateNextBuildNumber($metadata, $project, 0);
}

function execute_bumpVersion($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $dotNetLink = "https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core";
    $body = "Bumping [.NET version](" . $dotNetLink . ") on this branch! :arrow_heading_up:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "bump-version.yml");
}

function execute_cargoClippy($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [Cargo Clippy](https://doc.rust-lang.org/clippy/usage.html) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "cargo-clippy.yml");
}

function execute_codacyBypass($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $codacyUrl = "https://app.codacy.com/gh/{$comment->RepositoryOwner}/{$comment->RepositoryName}/pull-requests/{$comment->PullRequestNumber}/issues";
    $body = "Bypassing the Codacy analysis for this [pull request]({$codacyUrl})! :warning:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    bypassPullRequestAnalysis($comment->RepositoryOwner, $comment->RepositoryName, $comment->PullRequestNumber);
}

/**
 * Executes the process to reanalyze a commit in Codacy and updates GitHub with comments and reactions.
 *
 * @param array $config   Configuration data (currently unused).
 * @param array $metadata Metadata for the GitHub API request:
 *                        - 'token' (string): GitHub API token for authentication.
 *                        - 'reactionUrl' (string): URL to post a reaction to the comment.
 *                        - 'commentUrl' (string): URL to post a new comment.
 *                        - 'headSha' (string): SHA of the commit being reanalyzed.
 * @param object $comment Information about the triggering comment:
 *                        - RepositoryOwner (string): Repository owner.
 *                        - RepositoryName (string): Repository name.
 *                        - HeadSha (string): SHA of the associated commit.
 *
 * @return void
 */
function execute_codacyReanalyzeCommit($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $codacyUrl = "https://app.codacy.com/gh/{$comment->RepositoryOwner}/{$comment->RepositoryName}/commits/{$metadata["headSha"]}/issues";
    $body = "Reanalyzing the commit {$metadata["headSha"]} in [Codacy]({$codacyUrl})! :warning:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    reanalyzeCommit($comment->RepositoryOwner, $comment->RepositoryName, $metadata["headSha"]);
}

function execute_copyLabels($config, $metadata, $comment): void
{
    $pattern = '/\b(\w+)\/(\w+)\b/';
    preg_match($pattern, $comment->CommentBody, $matches);

    if (count($matches) !== 3) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $owner = $matches[1];
    $repository = $matches[2];
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");
    $body = array("body" => "Copying labels from [{$owner}/{$repository}](https://github.com/{$owner}/{$repository})! :label:");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], $body, "POST");

    $repositoryManager = new RepositoryManager();
    $labelsToCreate = $repositoryManager->getLabels($metadata["token"], $owner, $repository);
    $existingLabels = $repositoryManager->getLabels($metadata["token"], $comment->RepositoryOwner, $comment->RepositoryName);

    $labelsToUpdateObject = array();
    $labelsToCreate = array_filter($labelsToCreate, function ($label) use ($existingLabels, &$labelsToUpdateObject) {
        $existingLabel = array_filter($existingLabels, function ($existingLabel) use ($label) {
            return strtolower($existingLabel["name"]) === strtolower($label["name"]);
        });

        $total = count($existingLabel);

        if ($total > 0) {
            $existingLabel = array_values($existingLabel);
            $labelToUpdate = [];
            $labelToUpdate["color"] = $label["color"];
            $labelToUpdate["description"] = $label["description"];
            $labelToUpdate["new_name"] = $label["name"];
            $labelsToUpdateObject[$existingLabel[0]["name"]] = $labelToUpdate;
        }

        return $total === 0;
    });

    $labelsToCreateObject = array_map(function ($label) {
        $newLabel = [];
        $newLabel["color"] = substr($label["color"], 1);
        $newLabel["description"] = $label["description"];
        $newLabel["name"] = $label["name"];
        return $newLabel;
    }, $labelsToCreate);

    $totalLabelsToCreate = count($labelsToCreateObject);
    $totalLabelsToUpdate = count($labelsToUpdateObject);

    echo "Creating labels {$totalLabelsToCreate} | Updating labels: {$totalLabelsToUpdate}\n";

    if ($totalLabelsToCreate === 0 && $totalLabelsToUpdate === 0) {
        $body = array("body" => "No labels to create or update! :no_entry:");
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], $body, "POST");
        return;
    }

    $body = array("body" => "Creating {$totalLabelsToCreate} labels and updating {$totalLabelsToUpdate} labels! :label:");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], $body, "POST");

    $labelService = new LabelService();
    $labelService->processLabels($labelsToCreateObject, $labelsToUpdateObject, $metadata["token"], $metadata["labelsUrl"]);
}

function execute_copyIssue($config, $metadata, $comment): void
{
    preg_match(
        "/@" . $config->botName . "\scopy\sissue\s([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+)/",
        $comment->CommentBody,
        $matches
    );

    if (count($matches) !== 3) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");

    $issueUpdatedResponse = doRequestGitHub($metadata["token"], $metadata["issueUrl"], null, "GET");
    $issueUpdated = json_decode($issueUpdatedResponse->getBody());

    $targetRepository = $matches[1] . "/" . $matches[2];
    $newIssueUrl = "repos/" . $targetRepository . "/issues";
    $newIssue = array("title" => $issueUpdated->title, "body" => $issueUpdated->body, "labels" => $issueUpdated->labels);

    $createdIssueResponse = doRequestGitHub($metadata["token"], $newIssueUrl, $newIssue, "POST");
    if ($createdIssueResponse->getStatusCode() !== 201) {
        $body = "Error copying issue: {$createdIssueResponse->getStatusCode()}";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $createdIssue = json_decode($createdIssueResponse->getBody());

    $number = $createdIssue->number;

    $target = "{$targetRepository}#{$number}";
    $targetUrl = $createdIssue->html_url;

    $source = "{$comment->RepositoryOwner}/{$comment->RepositoryName}#{$comment->PullRequestNumber}";
    $sourceUrl = "https://github.com/{$comment->RepositoryOwner}/{$comment->RepositoryName}/issues/{$comment->PullRequestNumber}";

    $body = "Issue copied to [$target]({$targetUrl})";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");

    $body = "Issue copied from [$source]($sourceUrl)";
    doRequestGitHub($metadata["token"], "repos/{$targetRepository}/issues/{$number}/comments", array("body" => $body), "POST");
}

/**
 * Creates labels based on the provided configuration, metadata, and comment.
 *
 * @param array $config Configuration settings for label creation.
 * @param array $metadata Metadata information related to the labels.
 * @param string $comment The comment from which labels will be created.
 *
 * @return void
 */

function execute_createLabels($config, $metadata, $comment): void
{
    preg_match(
        "/@" . $config->botName . "\screate\slabels(?:\s(\w+))?(?:\s([\w,]+))?/",
        $comment->CommentBody,
        $matches
    );

    if (empty($matches) === true) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");

    $style = $matches[1] ?? "icons";
    $categories = $matches[2] ?? ["all"];

    $labelHelper = new LabelHelper();
    $result = $labelHelper->createLabels($metadata, $style, $categories);

    switch ($result["result"]) {
        case -1:
            $message = "No labels to create! :no_entry:";
            break;
        case 0:
            $message = "No labels to create or update! :no_entry:";
            break;
        default:
            $message = "Creating " . $result["totalLabelsToCreate"] . " labels and updating " . $result["totalLabelsToUpdate"] . " labels! :label:";
            break;
    }

    $body = array("body" => $message);
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], $body, "POST");
}

function execute_csharpier($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [CSharpier](https://csharpier.com/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "csharpier.yml");
}

function execute_fixCsproj($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Fixing [NuGet packages](https://nuget.org) references in .csproj files! :pill:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "fix-csproj.yml");
}

function execute_npmDist($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Generating the `dist` files via NPM! :building_construction:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "npm-dist.yml");
}

function execute_prettier($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [Prettier](https://prettier.io/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "prettier.yml");
}

/**
 * Executes rerun checks based on the provided configuration, metadata, and comment.
 *
 * @param array $config Configuration settings for the rerun checks.
 * @param array $metadata Metadata information related to the rerun checks.
 * @param string $comment The comment triggering the rerun checks.
 *
 * @return void
 */
function execute_rerunWorkflows($config, $metadata, $comment): void
{
    $commandHelper = new CommandHelper();
    $type = $commandHelper->getConclusionFromComment("workflows", $config->botName, $metadata, $comment);

    if ($type === null) {
        return;
    }

    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->getBody());
    $commitSha1 = $pullRequestUpdated->head->sha;
    $failedWorkflowRunsResponse = doRequestGitHub($metadata["token"], $metadata["repoPrefix"] . "/actions/runs?head_sha=" . $commitSha1 . "&status=" . $type, null, "GET");
    $failedWorkflowRuns = json_decode($failedWorkflowRunsResponse->getBody());
    $total = $failedWorkflowRuns->total_count;

    $body = "Rerunning " . $total . " " . $type . " workflow" . ($total === 1 ? "" : "s") . " on the commit `" . $commitSha1 . "`! :repeat:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    if ($total === 0) {
        return;
    }

    $actionsToRerun = "Rerunning the following workflows: \n";
    foreach ($failedWorkflowRuns->workflow_runs as $failedWorkflowRun) {
        $url = $metadata["repoPrefix"] . "/actions/runs/" . $failedWorkflowRun->id . "/rerun-failed-jobs";
        $response = doRequestGitHub($metadata["token"], $url, null, "POST");
        $status = $response->getStatusCode() === 201 ? "🔄" : "❌";
        $actionsToRerun .= "- [" . $failedWorkflowRun->name . "](" . $failedWorkflowRun->html_url . ") - " . $status . "\n";
    }

    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $actionsToRerun), "POST");
}

function execute_review($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->getBody());

    $commitsResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/commits?per_page=100", null, "GET");
    $commits = json_decode($commitsResponse->getBody());

    $pullRequest = new \stdClass();
    $pullRequest->DeliveryId = $comment->DeliveryIdText;
    $pullRequest->HookId = $comment->HookId;
    $pullRequest->TargetId = $comment->TargetId;
    $pullRequest->TargetType = $comment->TargetType;
    $pullRequest->RepositoryOwner = $comment->RepositoryOwner;
    $pullRequest->RepositoryName = $comment->RepositoryName;
    $pullRequest->Id = $pullRequestUpdated->id;
    $pullRequest->Sender = $comment->PullRequestSender;
    $pullRequest->Number = $comment->PullRequestNumber;
    $pullRequest->NodeId = $comment->PullRequestNodeId;
    $pullRequest->Title = $pullRequestUpdated->title;
    $pullRequest->Ref = $pullRequestUpdated->head->ref;
    $pullRequest->InstallationId = $comment->InstallationId;

    upsertPullRequest($pullRequest);

    $commitsList = "";
    foreach ($commits as $commitItem) {
        $commit = new \stdClass();
        $commit->HookId = $comment->HookId;
        $commit->TargetId = $comment->TargetId;
        $commit->TargetType = $comment->TargetType;
        $commit->RepositoryOwner = $comment->RepositoryOwner;
        $commit->RepositoryName = $comment->RepositoryName;
        $commit->Ref = $pullRequestUpdated->head->ref;
        $commit->HeadCommitId = $commitItem->sha;
        $commit->HeadCommitTreeId = $commitItem->commit->tree->sha;
        $commit->HeadCommitMessage = $commitItem->commit->message;
        $commit->HeadCommitTimestamp = date("Y-m-d H:i:s", strtotime($commitItem->commit->author->date));
        $commit->HeadCommitAuthorName = $commitItem->commit->author->name;
        $commit->HeadCommitAuthorEmail = $commitItem->commit->author->email;
        $commit->HeadCommitCommitterName = $commitItem->commit->committer->name;
        $commit->HeadCommitCommitterEmail = $commitItem->commit->committer->email;
        $commit->InstallationId = $comment->InstallationId;

        $commitsList .= "SHA: `{$commitItem->sha}`\n";

        upsertPush($commit);
    }
    $body = "Reviewing this pull request! :eyes:\n";
    $body .= "Mergeable state: {$pullRequestUpdated->mergeable_state}\n\n";
    $body .= "Commits included:\n {$commitsList}";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_updateSnapshot($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Updating test snapshots"), "POST");
    callWorkflow($config, $metadata, $comment, "update-test-snapshot.yml");
}

function callWorkflow($config, $metadata, $comment, $workflow, $extendedParameters = null): void
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->getBody());

    $tokenBot = generateInstallationToken($config->botRepositoryInstallationId, $config->botRepository);
    $url = "repos/" . $config->botWorkflowsRepository . "/actions/workflows/" . $workflow . "/dispatches";
    $data = array(
        "ref" => "main",
        "inputs" => array(
            "owner" => $comment->RepositoryOwner,
            "repository" => $comment->RepositoryName,
            "branch" => $pullRequest->head->ref,
            "pull_request" => $comment->PullRequestNumber,
            "installationId" => $comment->InstallationId
        )
    );
    if ($extendedParameters !== null) {
        $data["inputs"] = array_merge($data["inputs"], $extendedParameters);
    }
    doRequestGitHub($tokenBot, $url, $data, "POST");
}

function checkIfPullRequestIsOpen(&$metadata): bool
{
    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issueUrl"], null, "GET");
    if ($issueResponse->getStatusCode() !== 200) {
        return false;
    }

    $issue = json_decode($issueResponse->getBody());
    if (isset($issue->pull_request) === false || isset($issue->state) === false || $issue->state === "closed") {
        return false;
    }

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    if ($pullRequestResponse->getStatusCode() !== 200) {
        return false;
    }

    $pullRequest = json_decode($pullRequestResponse->getBody());

    $metadata["headRef"] = $pullRequest->head->ref;
    $metadata["headSha"] = $pullRequest->head->sha;

    return $pullRequest->state === "open";
}

function getAppVeyorProject($metadata, $comment)
{
    $project = findProjectByRepositorySlug($comment->RepositoryOwner . "/" . $comment->RepositoryName);

    if ($project == null) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Response is null"), "POST");
        return null;
    }

    if ($project->error) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $project->message;
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return null;
    }

    return $project;
}

function updateNextBuildNumber($metadata, $project, $nextBuildNumber): void
{
    $data = array("nextBuildNumber" => $nextBuildNumber);
    $url = "projects/" . $project->accountName . "/" . $project->slug . "/settings/build-number";
    $updateResponse = requestAppVeyor($url, $data, true);

    if ($updateResponse->getStatusCode() !== 204) {
        $commentBody = "AppVeyor update next build number failed: :x:\r\n\r\n```\r\n" . $updateResponse->getBody() . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }

    $commentBody = "AppVeyor next build number updated to " . $nextBuildNumber . "! :rocket:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function handleUpdateRepoVariableCommand($comment, $config)
{
    $commandExpression = "@" . $config->botName . " update repo variable ";
    if (stripos($comment->CommentBody, $commandExpression) === false) {
        return null;
    }

    $parts = explode(" ", str_ireplace($commandExpression, "", $comment->CommentBody));
    if (count($parts) < 2) {
        return "Error: Please provide both variable name and value.";
    }

    $name = $parts[0];
    $value = $parts[1];
    $owner = $comment->RepositoryOwner;
    $repo = $comment->RepositoryName;
    updateOrCreateRepoVariable($owner, $repo, $name, $value);
    return "Variable '{$name}' has been updated/created successfully.";
}

function updateOrCreateRepoVariable($owner, $repo, $name, $value)
{
    $request = new Request();
    $url = "https://api.github.com/repos/{$owner}/{$repo}/actions/variables";

    // Get existing variables
    $response = $request->get($url);
    $variables = json_decode($response->getBody(), true);

    $variableExists = false;
    foreach ($variables as $variable) {
        if ($variable['name'] === $name) {
            $variableExists = true;
            break;
        }
    }

    if ($variableExists) {
        $url .= "/{$name}";
        $request->patch($url, ['value' => $value]);

    } else {

        // TODO add code to create variable
    }
}
function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_comments";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->comments === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoComments, GUIDv4::random());
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
