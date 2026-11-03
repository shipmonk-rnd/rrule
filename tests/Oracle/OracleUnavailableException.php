<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Oracle;

use RuntimeException;

/**
 * The oracle cannot run in this environment (no FFI, no library, no Python). Tests skip when they get it.
 */
final class OracleUnavailableException extends RuntimeException
{

}
