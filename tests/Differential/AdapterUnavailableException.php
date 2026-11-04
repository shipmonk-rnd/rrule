<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Differential;

use RuntimeException;

/**
 * The reference implementation cannot run in this environment (no FFI, no library, no Python). Tests skip when they get it.
 */
final class AdapterUnavailableException extends RuntimeException
{

}
