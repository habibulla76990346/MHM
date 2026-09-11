<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\ChatRequest;
use Generator;

interface SupportsStreaming
{
    /** @return Generator<int, string> text fragments as they arrive */
    public function streamChat(ChatRequest $request): Generator;
}
