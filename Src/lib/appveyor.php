<?php

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;

function requestAppVeyor($url, $data = null, $isPut = false)
{
    global $appVeyorKey, $loggerUrl, $loggerApiKey, $loggerApiToken;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    $headers = array(
        "User-Agent: " . USER_AGENT,
        "Authorization: Bearer " . $appVeyorKey,
        "Content-Type: application/json"
    );

    $logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken, USER_AGENT);
    $request = new Request();

    $response = null;

    if ($data != null) {
        $response = $isPut
            ? $request->put($url, json_encode($data), $headers)
            : $request->post($url, json_encode($data), $headers);
    } else {
        $response = $request->get($url, $headers);
    }

    if ($response->statusCode >= 300) {
        $info = json_encode(array("url" => $url, "request" => json_encode($data, true), "response" => $response));
        $logger->log("Error on AppVeyor request", $info);
    }

    return $response;
}

function findProjectByRepositorySlug($repositorySlug)
{
    $searchSlug = strtolower($repositorySlug);

    $projectsResponse = requestAppVeyor("projects");
    if ($projectsResponse == null) {
        return null;
    }

    $projects = json_decode($projectsResponse->body);
    if (isset($projects->message) && !empty($projects->message)) {
        $error = new \stdClass();
        $error->error = true;
        $error->message = $projects->message;
        return $error;
    }

    $projects = array_filter($projects, function ($p) use ($searchSlug) {
        return $searchSlug === strtolower($p->repositoryName);
    });
    $projects = array_values($projects);

    $result = $projects[0];
    $result->error = false;

    return $result;
}