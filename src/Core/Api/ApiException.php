<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Api;

/**
 * Error returned by the Virtualbadge.io API or while talking to it.
 *
 * Status 0 means the request did not reach the API (network error).
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $detail,
        public readonly ?int $retryAfter = null,
        public readonly bool $ownLimit = false
    ) {
        parent::__construct($status === 0 ? $detail : 'Virtualbadge.io error ' . $status . ': ' . $detail, $status);
    }

    /**
     * Network errors, limits and server errors may succeed later.
     */
    public function isTemporary(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
