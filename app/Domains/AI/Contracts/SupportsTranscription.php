<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\Transcript;
use App\Domains\AI\DTO\TranscriptionRequest;

/** An adapter that can turn speech into words (§18). */
interface SupportsTranscription
{
    public function transcribe(TranscriptionRequest $request): Transcript;
}
