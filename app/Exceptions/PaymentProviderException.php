<?php

namespace App\Exceptions;

use Exception;

/**
 * A user-safe error raised when a payment provider (e.g. Paystack) call fails.
 * The exception message is written to be shown directly to the end user;
 * provider-specific details belong in the log context, not this message.
 */
class PaymentProviderException extends Exception
{
    //
}
