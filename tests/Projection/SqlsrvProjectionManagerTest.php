<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 10:00 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Projection;

use PDO;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Pdo\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\Exception\RuntimeException;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvAggregateStreamStrategy;
use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use ProophTest\EventStore\Pdo\TestUtil;
use ProophTest\EventStore\Projection\AbstractProjectionManagerTest;

/**
 * @group sqlsrv
 */
class SqlsrvProjectionManagerTest extends AbstractProjectionManagerTest
{
    /**
   * @var SqlsrvProjectionManager
   */
  protected $projectionManager;

  /**
   * @var SqlsrvEventStore
   */
  private $eventStore;

  /**
   * @var PDO
   */
  private $connection;

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
      new SqlsrvAggregateStreamStrategy()
    );
        $this->projectionManager = new SqlsrvProjectionManager($this->eventStore, $this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->exec('DROP TABLE IF EXISTS event_streams;');
        $this->connection->exec('DROP TABLE IF EXISTS projections;');
    }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_event_store_instance_passed(): void
  {
      $this->expectException(\Prooph\EventStore\Exception\InvalidArgumentException::class);

      $eventStore = $this->prophesize(EventStore::class);

      new SqlsrvProjectionManager($eventStore->reveal(), $this->connection);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_wrapped_event_store_instance_passed(): void
  {
      $this->expectException(InvalidArgumentException::class);

      $eventStore = $this->prophesize(EventStore::class);
      $wrappedEventStore = $this->prophesize(EventStoreDecorator::class);
      $wrappedEventStore->getInnerEventStore()->willReturn($eventStore->reveal())->shouldBeCalled();

      new SqlsrvProjectionManager($wrappedEventStore->reveal(), $this->connection);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_fetching_projecton_names_with_missing_db_table(): void
  {
      $this->expectException(RuntimeException::class);

      $this->connection->exec('DROP TABLE projections;');
      $this->projectionManager->fetchProjectionNames(null, 200, 0);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_fetching_projecton_names_regex_with_missing_db_table(): void
  {
      $this->expectException(RuntimeException::class);

      $this->connection->exec('DROP TABLE projections;');
      $this->projectionManager->fetchProjectionNamesRegex('foo%', 200, 0);
  }
}
