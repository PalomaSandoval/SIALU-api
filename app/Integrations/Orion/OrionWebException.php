<?php

namespace App\Integrations\Orion;

use RuntimeException;

/** Failure of the Orion web console session. Its message is safe to show: it never carries credentials or cookies. */
class OrionWebException extends RuntimeException
{
}
