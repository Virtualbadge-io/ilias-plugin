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
 * Sends one HTTP request. Replaced by a fake in tests.
 */
interface HttpTransport
{
    /**
     * @param string[] $headers complete header lines, e.g. "Accept: application/json"
     * @throws ApiException with status 0 if the request could not be sent
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
