<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\PDO\Exception;

class ExtensionNotLoaded extends RuntimeException
{
    public static function with(string $extensionNme): ExtensionNotLoaded
    {
        return new self(
            sprintf(
                'Extension "' . $extensionNme . '" is not loaded'
            )
        );
    }
}