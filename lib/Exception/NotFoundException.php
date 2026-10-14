<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * Uniform "not found" — thrown for missing AND out-of-scope entities so the
 * wire payload never becomes an existence oracle (family api-security rule §1).
 */
class NotFoundException extends \RuntimeException
{
}
