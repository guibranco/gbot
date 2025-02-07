<?php
use Src\LabelHandler;

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
    global $gitHubUserToken, $gitHubWebhookEndpoint, $gitHubWebhookSignature;
use GuiBranco\Pancake\HealthChecks;

function handleItem($signature)
{
    global $gitHubUserToken, $gitHubWebhookEndpoint, $gitHubWebhookSignature;
    $githubClient = new GitHubClient($gitHubUserToken);
    $labelHandler = new LabelHandler($githubClient);

    // Example payload processing
    $payload = json_decode($signature, true);

    $request = array(
        "content_type" => "json",
        "insecure_ssl" => "0",
        "url" => $gitHubWebhookEndpoint,
);

if (isset($payload['comment']['body']) && isset($payload['issue']['number'])) {
    $commentBody = $payload['comment']['body'];
    $issueOrPrNumber = $payload['issue']['number'];
    $repository = $payload['repository']['full_name'];
        "secret" => $gitHubWebhookSignature
    );

    $repoPrefix = "repos/" . $signature->RepositoryOwner . "/" . $signature->RepositoryName;
    $url = "";
    if ($signature->TargetType == "repository") {
        $url = $repoPrefix . "/hooks/" . $signature->HookId . "/config";
    } elseif ($signature->TargetType == "organization") {
        $url = "repos/" . $signature->RepositoryOwner . "/hooks/" . $signature->HookId . "/config";
    }

    if (!empty($url)) {
        doRequestGitHub($gitHubUserToken, $url, $request, "POST");
    }
}


$healthCheck = new HealthChecks($healthChecksIoSignature, GUIDv4::random());
$processor = new ProcessingManager("signature", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
