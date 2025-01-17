<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\HealthChecks;
use GuiBranco\Pancake\Logger;

class ProcessingManager
{
    private $config;
    private $entity;
    private $healthChecks;
    private $logger;

    public function __construct(string $entity, HealthChecks $healthChecks, Logger $logger)
    {
        $this->config = loadConfig();
        $this->entity = $entity;
        $this->healthChecks = $healthChecks;
        $this->logger = $logger;

        $this->healthChecks->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
    }

    public function initialize(callable $handler, int $timeout): void
    {
        $this->healthChecks->start();
        $time = time();
        while (true) {
            $this->batch($handler);
            $limit = ($time + $timeout);
            if ($limit < time()) {
                break;
            }
        }
        $this->healthChecks->end();
    }

    private function batch(callable $handler): void
    {
        ob_start();
        $this->process($handler);
        $result = ob_get_clean();
        if ($this->config->debug->all === true || $this->config->debug->{$this->entity} === true) {
            echo $result;
        }
    }

    private function process(callable $handler): void
    {
        $items = readTable("github_{$this->entity}");
        foreach ($items as $item) {
            echo "Sequence: {$item->Sequence}\n";
            echo "Delivery ID: {$item->DeliveryIdText}\n";
            $this->processItem($item, $handler);
            echo str_repeat("=-", 50) . "=\n";
        }
    }

    private function processItem($item, callable $handler): void
    {
        $details = json_encode($item);
        if ($details === false) {
            $details = json_last_error_msg();
        }

        try {
            if (updateTable("github_{$this->entity}", $item->Sequence)) {
                $handler($item);
                if(finalizeProcessing("github_{$this->entity}", $item->Sequence)) {
                    echo "Item processed!\n";
                } else {
                    echo "Item updated by another hook!\n";
                }
                return;
            }

            $message = "Skipping item (Entity: {$this->entity}, Sequence: {$item->Sequence}) since it was already handled.";
            $this->logger->log($message, $details);
            echo $message . "\n";
        } catch (\Exception $e) {
            $this->logger->log(
                "Failed to process item (Entity: {$this->entity}, Sequence: {$item->Sequence}): {$e->getMessage()}.",
                [
                    'error' => [
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ],
                    'item' => json_decode($details, true)
                ]
            );
            throw $e;
        }
    }
}
