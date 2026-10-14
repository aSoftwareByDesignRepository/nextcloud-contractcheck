<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * The caller lacks the required capability for this route class
 * (app access door / role gate) — distinct from "entity not in scope",
 * which must surface as NotFoundException instead.
 */
class ForbiddenException extends \RuntimeException
{
}
