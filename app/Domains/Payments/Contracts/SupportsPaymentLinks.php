<?php

namespace App\Domains\Payments\Contracts;

/**
 * Declares that this gateway genuinely handles hosted payment links.
 *
 * A marker rather than a config flag: an adapter that does not implement it
 * cannot be selected for work that needs it, and the guarantee holds at the
 * type level rather than depending on a row being filled in correctly.
 */
interface SupportsPaymentLinks extends PaymentGateway {}
