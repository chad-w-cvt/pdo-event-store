<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:07 AM
 */

namespace Prooph\EventStore\Pdo\Container;

use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;

class SqlsrvProjectionManagerFactory extends AbstractProjectionManagerFactory
{
    protected function projectionManagerClassName(): string
    {
        return SqlsrvProjectionManager::class;
    }
}
