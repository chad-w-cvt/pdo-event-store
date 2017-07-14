<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:58 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Projection;

use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;
use ProophTest\EventStore\Pdo\TestUtil;

/**
 * @group sqlsrv
 */
class SqlsrvEventStoreQueryTest extends PdoEventStoreQueryTest
{
  protected function setUp(): void
  {
    if (TestUtil::getDatabaseDriver() !== 'pdo_sqlsrv') {
      throw new \RuntimeException('Invalid database driver');
    }

    $this->isMariaDb = false;

    $this->connection = TestUtil::getConnection();
    TestUtil::initDefaultDatabaseTables($this->connection);

    $this->eventStore = new SqlsrvEventStore(
      new FQCNMessageFactory(),
      $this->connection,
      new SqlsrvSimpleStreamStrategy()
    );

    $this->projectionManager = new SqlsrvProjectionManager(
      $this->eventStore,
      $this->connection
    );
  }
}
