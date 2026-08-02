<?php

declare(strict_types=1);

namespace Infocyph\PHPProbe\Comment;

final readonly class CommentFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public int $endLine,
        public string $type,
        public string $severity,
        public string $message,
        public string $confidence = 'medium',
        public ?string $subtype = null,
        public ?string $explanation = null,
        public ?string $suggestion = null,
        public ?string $tag = null,
        public ?string $scope = null,
        public ?string $issue = null,
        public ?string $owner = null,
        public ?string $reason = null,
        public ?string $raw = null,
    ) {}

    /**
     * @return array{
     *     file:string,
     *     line:int,
     *     end_line:int,
     *     type:string,
     *     severity:string,
     *     message:string,
     *     confidence:string,
     *     subtype:?string,
     *     explanation:?string,
     *     suggestion:?string,
     *     tag:?string,
     *     scope:?string,
     *     issue:?string,
     *     owner:?string,
     *     reason:?string,
     *     raw:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'end_line' => $this->endLine,
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'confidence' => $this->confidence,
            'subtype' => $this->subtype,
            'explanation' => $this->explanation,
            'suggestion' => $this->suggestion,
            'tag' => $this->tag,
            'scope' => $this->scope,
            'issue' => $this->issue,
            'owner' => $this->owner,
            'reason' => $this->reason,
            'raw' => $this->raw,
        ];
    }
}
