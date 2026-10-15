<?php

namespace App\Payments;

use RuntimeException;

/** The provider refused, could not be reached, or sent something that failed verification. */
class GatewayException extends RuntimeException {}
