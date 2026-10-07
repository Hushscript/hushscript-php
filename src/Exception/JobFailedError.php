<?php

declare(strict_types=1);

namespace Hushscript\Exception;

use Hushscript\Redact;

final class JobFailedError extends HushscriptException
{
    public const DEFAULT_CODE = 'job_failed';

    public readonly ?string $jobId;

    public readonly ?string $error;

    public readonly ?string $failureKind;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        ?string $jobId = null,
        ?string $error = null,
        ?string $failureKind = null,
        ?string $errorCode = null,
        array $details = [],
    ) {
        parent::__construct($message, errorCode: $errorCode, details: $details);
        $this->jobId = $jobId;
        $this->error = $error === null ? null : Redact::text($error);
        $this->failureKind = $failureKind;
    }
}
