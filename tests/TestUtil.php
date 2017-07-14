<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2017 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2017 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo;

use PDO;

abstract class TestUtil
{
    /**
     * List of URL schemes from a database URL and their mappings to driver.
     */
    private static $driverSchemeAliases = [
        'pdo_mysql' => 'mysql',
        'pdo_pgsql' => 'pgsql',
        'pdo_sqlsrv' => 'sqlsrv',
    ];

    private static $driverSchemeSeparators = [
        'pdo_mysql' => ';',
        'pdo_pgsql' => ' ',
        'pdo_sqlsrv' => ';',
    ];

    /**
     * @var PDO
     */
    private static $connection;

    public static function getConnection(): PDO
    {
        if (! isset(self::$connection)) {
            $connectionParams = self::getConnectionParams();
            $separator = self::$driverSchemeSeparators[$connectionParams['driver']];
            $dsn = self::$driverSchemeAliases[$connectionParams['driver']] . ':';

            if ($connectionParams['driver'] === 'pdo_sqlsrv') {
              $dsn .= 'server=' . $connectionParams['host'] . $separator;
            } else {
              $dsn .= 'host=' . $connectionParams['host'] . $separator;
            }

            if (!empty($connectionParams['port'])) {
              $dsn .= 'port=' . $connectionParams['port'] . $separator;
            }

            if ($connectionParams['driver'] === 'pdo_sqlsrv') {
              $dsn .= 'Database=' . $connectionParams['dbname'] . $separator;
            } else {
              $dsn .= 'dbname=' . $connectionParams['dbname'] . $separator;
              $dsn .= self::getCharsetValue($connectionParams['charset'], $connectionParams['driver']) . $separator;
            }

            $dsn = rtrim($dsn);
            self::$connection = new PDO($dsn, $connectionParams['user'], $connectionParams['password'], $connectionParams['options']);
        }

        try {
            self::$connection->rollBack();
        } catch (\PDOException $e) {
            // ignore
        }

        return self::$connection;
    }

    public static function getDatabaseName(): string
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return getenv('DB_NAME');
    }

    public static function getDatabaseDriver(): string
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return getenv('DB_DRIVER');
    }

    public static function getDatabaseVendor(): string
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return explode('_', getenv('DB'))[0];
    }

    public static function getConnectionParams(): array
    {
        if (! self::hasRequiredConnectionParams()) {
            throw new \RuntimeException('No connection params given');
        }

        return self::getSpecifiedConnectionParams();
    }

    public static function initDefaultDatabaseTables(PDO $connection): void
    {
        $vendor = self::getDatabaseVendor();

        $connection->exec('DROP TABLE IF EXISTS event_streams');
        $create = file_get_contents(__DIR__.'/../scripts/' . $vendor . '/01_event_streams_table.sql');
        $result = $connection->exec($create);
        $connection->exec('DROP TABLE IF EXISTS projections');
        $create = file_get_contents(__DIR__.'/../scripts/' . $vendor . '/02_projections_table.sql');
        $result = $connection->exec($create);
    }

    private static function hasRequiredConnectionParams(): bool
    {
        $env = getenv();

        return isset(
            $env['DB'],
            $env['DB_DRIVER'],
            $env['DB_USERNAME'],
            $env['DB_PASSWORD'],
            $env['DB_HOST'],
            $env['DB_NAME'],
            $env['DB_CHARSET']
        );
    }

    private static function getSpecifiedConnectionParams(): array
    {
        return [
            'driver' => getenv('DB_DRIVER'),
            'user' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'host' => getenv('DB_HOST'),
            'dbname' => getenv('DB_NAME'),
            'port' => getenv('DB_PORT'),
            'charset' => getenv('DB_CHARSET'),
            'options' => [PDO::ATTR_ERRMODE => (int) getenv('DB_ATTR_ERRMODE')],
        ];
    }

    private static function getCharsetValue(string $charset, string $driver): string
    {
        if ('pdo_pgsql' === $driver) {
            return "options='--client_encoding=$charset'";
        }

        return "charset=$charset";
    }
}
