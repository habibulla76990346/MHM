<?php

namespace App\Domains\AI\Contracts;

use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;

interface SupportsChat
{
    public function chat(ChatRequest $request): ChatResponse;
}
