<?php

namespace GuiBranco\GStracciniBot\Library;

class MarkdownGroupCheckboxValidator
{
    public function validateCheckboxes(string $prBody): array
    {
        $prBody = str_replace("\r", "", $prBody);
        $groupPattern = '/##\s(.+)\n(?:\<!--.*?--\>\n)?((?:- \[(.)\] .+\n)+)/i';
        $acceptanceCriteriaPattern = '/##\s*Acceptance Criteria\s*\n(\s*- \[ \].+\n?)+/i';
        $checkboxPattern = "/- \[(x| )\] (.+)/i";

        $report = [
            "found" => 0,
            "groups" => [],
            "errors" => [],
        ];

        $found = preg_match_all(
            $groupPattern,
            $prBody,
            $groupMatches,
            PREG_SET_ORDER
        );
        $report["found"] = $found;
        // Check for Acceptance Criteria specifically
        if (preg_match($acceptanceCriteriaPattern, $prBody)) {
            $report['found'] += 1;
        }

        if (!$found) {
            return $report;
        }

        foreach ($groupMatches as $groupMatch) {
            $groupTitle = trim($groupMatch[1]);

            preg_match_all(
                $checkboxPattern,
                $groupMatch[0],
                $checkboxMatches,
                PREG_SET_ORDER
            );

            $groupResult = [
                "group" => $groupTitle,
                "checked" => [],
                "unchecked" => [],
            ];

            $checkedCount = 0;
            $checkboxTexts = [];
            foreach ($checkboxMatches as $checkboxMatch) {
                $checkboxText = trim($checkboxMatch[2]);
                $checkboxTexts[] = strtolower($checkboxText);
                if (strtolower($checkboxMatch[1]) === "x") {
                    $groupResult["checked"][] = $checkboxText;
                    $checkedCount++;
                } else {
                    $groupResult["unchecked"][] = $checkboxText;
                }
            }

            if (
                count($checkboxMatches) === 2 &&
                in_array("yes", $checkboxTexts) &&
                in_array("no", $checkboxTexts) &&
                $checkedCount !== 1
            ) {
                $report["errors"][] = "Invalid selection in group: $groupTitle. Please select exactly one option (Yes or No).";
            }

            if ($checkedCount === 0) {
                $report["errors"][] = "No checkbox selected in group: $groupTitle";
            }

            $report["groups"][] = $groupResult;
        }

        return $report;
    }

    public function generateReport(array $validationResult): string
    {
        if (
            isset($validationResult["errors"]) &&
            !empty($validationResult["errors"])
        ) {
            return implode("\n", $validationResult["errors"]);
        }

        $report = "Checkbox validation report:\n";

        foreach ($validationResult["groups"] as $group) {
            $report .= "\n{$group["group"]}\n";

            if (!empty($group["checked"])) {
                $report .= "Checked items:\n";
                foreach ($group["checked"] as $checkedItem) {
                    $report .= "- $checkedItem\n";
                }
            } else {
                $report .= "No checked items.\n";
            }

            if (!empty($group["unchecked"])) {
                $report .= "Unchecked items:\n";
                foreach ($group["unchecked"] as $uncheckedItem) {
                    $report .= "- $uncheckedItem\n";
                }
            }
        }

        return $report;
    }
}
