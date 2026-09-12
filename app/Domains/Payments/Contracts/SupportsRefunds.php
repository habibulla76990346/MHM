<?php

namespace App\Domains\Payments\Contracts;

use App\Domains\Payments\DTO\RefundRequest;
use App\Domains\Payments\DTO\RefundResult;

/**
 * Declares that this gateway genuinely handles refunds.
 *
 * A marker rather than a config flag: an adapter that does not implement it
 * cannot be selected for work that needs it, and the guarantee holds at the
 * type level rather than depending on a row being filled in correctly.
 */
interface SupportsRefunds extends PaymentGateway
{
    public function refund(RefundRequest $request): RefundResult;
}
