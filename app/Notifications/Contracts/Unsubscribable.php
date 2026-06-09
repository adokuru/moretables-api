<?php

namespace App\Notifications\Contracts;

/**
 * Marks a notification as honoring email unsubscribes. Security-critical mail
 * (auth codes, password resets) must NOT implement this so it always sends.
 */
interface Unsubscribable {}
