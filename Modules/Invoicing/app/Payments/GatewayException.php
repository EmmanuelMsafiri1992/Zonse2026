<?php

namespace Modules\Invoicing\Payments;

use RuntimeException;

/** The provider refused a request, could not be reached, or sent something that failed verification. */
class GatewayException extends RuntimeException {}
