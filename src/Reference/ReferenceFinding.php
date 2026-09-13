<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Reference;

final readonly class ReferenceFinding
{
    /**
     * @param list<array{fqcn:string,confidence:float}> $candidates
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $type,
        public string $symbol,
        public string $message,
        public string $confidence,
        public ?string $suggestion = null,
        public array $candidates = [],
    ) {}

    /**
     * @return array{
     *     file:string,
     *     line:int,
     *     type:string,
     *     severity:string,
     *     symbol:string,
     *     message:string,
     *     confidence:string,
     *     suggestion:?string,
     *     candidates:list<array{fqcn:string,confidence:float}>
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'type' => $this->type,
            'severity' => 'error',
            'symbol' => $this->symbol,
            'message' => $this->message,
            'confidence' => $this->confidence,
            'suggestion' => $this->suggestion,
            'candidates' => $this->candidates,
        ];
    }
}
