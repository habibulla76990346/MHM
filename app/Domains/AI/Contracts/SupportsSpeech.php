<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\SpeechRequest;
use App\Domains\AI\DTO\SynthesisedSpeech;

/** An adapter that can read words aloud (§18). */
interface SupportsSpeech
{
    public function synthesise(SpeechRequest $request): SynthesisedSpeech;
}
