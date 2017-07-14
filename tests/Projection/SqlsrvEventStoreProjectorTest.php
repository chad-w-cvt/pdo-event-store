<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:57 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Projection;

use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Pdo\TestUtil;

/**
 * @group sqlsrv
 */
class SqlsrvEventStoreProjectorTest extends PdoEventStoreProjectorTest
{
  protected function setUp(): void
  {
    if (TestUtil::getDatabaseDriver() !== 'pdo_sqlsrv') {
      throw new \RuntimeException('Invalid database driver');
    }

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

  /**
   * @test
   */
  public function it_handles_missing_projection_table(): void
  {
    $this->expectException(\Prooph\EventStore\Pdo\Exception\RuntimeException::class);
    $this->expectExceptionMessage("Error 42S02. Maybe the projection table is not setup?\nError-Info: [Microsoft][ODBC Driver 13 for SQL Server][SQL Server]Invalid object name 'projections'");

    $this->prepareEventStream('user-123');

    $this->connection->exec('DROP TABLE projections;');

    $projection = $this->projectionManager->createProjection('test_projection');

    $projection
      ->fromStream('user-123')
      ->when([
        UserCreated::class => function (array $state, UserCreated $event): array {
          $this->stop();

          return $state;
        },
      ])
      ->run();
  }
}
