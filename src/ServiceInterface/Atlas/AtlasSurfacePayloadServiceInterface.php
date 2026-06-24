<?php

declare(strict_types=1);

namespace App\ServiceInterface\Atlas;

use App\ServiceInterface\Surface\AtlasSurfacePayloadServiceInterface as CanonicalAtlasSurfacePayloadServiceInterface;

/**
 * Backward-compatible alias for the W01 skeleton surface contract.
 *
 * New code should type against App\ServiceInterface\Surface\AtlasSurfacePayloadServiceInterface.
 */
interface AtlasSurfacePayloadServiceInterface extends CanonicalAtlasSurfacePayloadServiceInterface
{
}
