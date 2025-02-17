<?php

namespace GuiBranco\GStracciniBot\Library;

class LabelHandler
{
    private $githubClient;

    public function __construct($githubClient)
    {
        $this->githubClient = $githubClient;
    }

    public function handleInvalidLabels($commentBody, $issueOrPrNumber, $repository)
    {
        $missingLabels = $this->detectMissingLabels($commentBody);
        foreach ($missingLabels as $label) {
            $this->createLabel($label, $repository);
            $this->assignLabel($label, $issueOrPrNumber, $repository);
        }
    }

    private function detectMissingLabels($commentBody)
    {
        // Use regex to detect missing labels in the comment body
        preg_match_all('/label:\s*(\w+)/', $commentBody, $matches);
        return $matches[1];
    }

    private function createLabel($label, $repository)
    {
        // Use GitHub API to create the label
        $this->githubClient->createLabel($repository, $label, 'f29513', 'Automatically created label');
    }

    private function assignLabel($label, $issueOrPrNumber, $repository)
    {
        // Use GitHub API to assign the label to the issue or PR
        $this->githubClient->assignLabel($repository, $issueOrPrNumber, $label);
    }
}
