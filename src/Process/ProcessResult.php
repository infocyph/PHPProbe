<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
        public bool $outputLimitExceeded = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->outputLimitExceeded;
    }
}
