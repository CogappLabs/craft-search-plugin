<?php

namespace cogapp\searchindex\tests\unit\services\Support;

use yii\caching\ArrayCache;
use yii\db\Connection;

class TestProjectConfig
{
    public bool $applyExternalChanges = false;
    public array $setCalls = [];
    public array $removeCalls = [];
    public array $processCalls = [];

    public function getIsApplyingExternalChanges(): bool
    {
        return $this->applyExternalChanges;
    }

    public function get(string $path, bool $parse = false): mixed
    {
        return null;
    }

    public function processConfigChanges(string $path, bool $force = false): void
    {
        $this->processCalls[] = ['path' => $path, 'force' => $force];
    }

    public function set(string $path, mixed $value): void
    {
        $this->setCalls[] = ['path' => $path, 'value' => $value];
    }

    public function remove(string $path): void
    {
        $this->removeCalls[] = $path;
    }
}

class TestQueue
{
    public array $jobs = [];
    public ?int $currentPriority = null;
    public ?int $currentDelay = null;
    public ?int $currentTtr = null;
    public bool $throwOnPush = false;

    public function priority(?int $priority): self
    {
        $this->currentPriority = $priority;

        return $this;
    }

    public function delay(?int $delay): self
    {
        $this->currentDelay = $delay;

        return $this;
    }

    public function ttr(?int $ttr): self
    {
        $this->currentTtr = $ttr;

        return $this;
    }

    public function push(object $job): string
    {
        if ($this->throwOnPush) {
            throw new \RuntimeException('Queue push failed.');
        }

        $this->jobs[] = [
            'job' => $job,
            'priority' => $this->currentPriority,
            'delay' => $this->currentDelay,
            'ttr' => $this->currentTtr,
        ];

        return (string)count($this->jobs);
    }
}

class TestMutex
{
    public bool $acquireResult = true;
    public array $acquireCalls = [];
    public array $releaseCalls = [];

    public function acquire(string $name, int $timeout = 0): bool
    {
        $this->acquireCalls[] = ['name' => $name, 'timeout' => $timeout];

        return $this->acquireResult;
    }

    public function release(string $name): bool
    {
        $this->releaseCalls[] = $name;

        return true;
    }
}

class TestApp
{
    public ArrayCache $cache;
    public TestQueue $queue;
    public TestMutex $mutex;
    public TestProjectConfig $projectConfig;
    public Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->cache = new ArrayCache();
        $this->queue = new TestQueue();
        $this->mutex = new TestMutex();
        $this->projectConfig = new TestProjectConfig();
        $this->db = $db ?? new Connection([
            'dsn' => 'sqlite::memory:',
        ]);
        $this->db->open();
    }

    public function getCache(): ArrayCache
    {
        return $this->cache;
    }

    public function getQueue(): TestQueue
    {
        return $this->queue;
    }

    public function getMutex(): TestMutex
    {
        return $this->mutex;
    }

    public function getProjectConfig(): TestProjectConfig
    {
        return $this->projectConfig;
    }

    public function getDb(): Connection
    {
        return $this->db;
    }
}
