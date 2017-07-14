<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:59 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Projection;

use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;
use Prooph\EventStore\Projection\ReadModel;
use ProophTest\EventStore\Mock\ReadModelMock;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Pdo\TestUtil;

/**
 * @group sqlsrv
 */
class SqlsrvEventStoreReadModelProjectorTest extends PdoEventStoreReadModelProjectorTest
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
  public function it_calls_reset_projection_also_if_init_callback_returns_state()
  {
    $readModel = $this->prophesize(ReadModel::class);
    $readModel->reset()->shouldBeCalled();

    $readModelProjection = $this->projectionManager->createReadModelProjection('test-projection', $readModel->reveal());

    $readModelProjection->init(function () {
      return ['state' => 'some value'];
    });

    $readModelProjection->reset();
  }

  /**
   * @test
   */
  public function it_handles_missing_projection_table(): void
  {
    $this->expectException(\Prooph\EventStore\Pdo\Exception\RuntimeException::class);
//    $this->expectExceptionMessage("Error 42S02. Maybe the projection table is not setup?\nError-Info: Table 'event_store_tests.projections' doesn't exist");

    $this->prepareEventStream('user-123');

    $this->connection->exec('DROP TABLE projections;');

    $projection = $this->projectionManager->createReadModelProjection('test_projection', new ReadModelMock());

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
