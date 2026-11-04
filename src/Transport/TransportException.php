<?php

declare(strict_types=1);

namespace Beacon\Transport;

/** The request never got an HTTP response (DNS, TLS, timeout, connection refused…). */
class TransportException extends \RuntimeException {}
