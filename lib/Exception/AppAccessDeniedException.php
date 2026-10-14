<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * Access door closed — user has no app access at all. Rendered as the
 * friendly access-denied page for HTML routes and 403 JSON for APIs.
 */
class AppAccessDeniedException extends \RuntimeException
{
}
