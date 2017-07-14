<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:52 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo;

use ArrayIterator;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Exception\ConcurrencyException;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use Prooph\EventStore\Pdo\Exception\RuntimeException;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvAggregateStreamStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy\SqlsrvSingleStreamStrategy;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;
use Ramsey\Uuid\Uuid;

/**
 * @group sqlsrv
 */
final class SqlsrvEventStoreTest extends AbstractPdoEventStoreTest
{
    /**
   * @var SqlsrvEventStore
   */
  protected $eventStore;

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
    }

  /**
   * @test
   */
  public function it_cannot_create_new_stream_if_table_name_is_already_used(): void
  {
      $this->expectException(RuntimeException::class);
      $this->expectExceptionMessage('Error during createSchemaFor');

      $streamName = new StreamName('foo');
      $strategy = new SqlsrvAggregateStreamStrategy();
      $schema = $strategy->createSchema($strategy->generateTableName($streamName));

      foreach ($schema as $command) {
          $statement = $this->connection->prepare($command);
          $statement->execute();
      }

      $this->eventStore->create(new Stream($streamName, new ArrayIterator()));
  }

  /**
   * @test
   */
  public function it_loads_correctly_using_single_stream_per_aggregate_type_strategy(): void
  {
      $this->eventStore = new SqlsrvEventStore(
      new FQCNMessageFactory(),
      $this->connection,
      new SqlsrvSingleStreamStrategy(),
      5
    );

      $streamName = new StreamName('Prooph\Model\User');

      $stream = new Stream($streamName, new ArrayIterator($this->getMultipleTestEvents()));

      $this->eventStore->create($stream);

      $metadataMatcher = new MetadataMatcher();
      $metadataMatcher = $metadataMatcher->withMetadataMatch('_aggregate_id', Operator::EQUALS(), 'one');
      $events = iterator_to_array($this->eventStore->load($streamName, 1, null, $metadataMatcher));
      $this->assertCount(100, $events);
      $lastUser1Event = array_pop($events);

      $metadataMatcher = new MetadataMatcher();
      $metadataMatcher = $metadataMatcher->withMetadataMatch('_aggregate_id', Operator::EQUALS(), 'two');
      $events = iterator_to_array($this->eventStore->load($streamName, 1, null, $metadataMatcher));
      $this->assertCount(100, $events);
      $lastUser2Event = array_pop($events);

      $this->assertEquals('Sandro', $lastUser1Event->payload()['name']);
      $this->assertEquals('Bradley', $lastUser2Event->payload()['name']);
  }

  /**
   * @test
   */
  public function it_fails_to_write_with_duplicate_version_and_mulitple_streams_per_aggregate_strategy(): void
  {
      $this->expectException(ConcurrencyException::class);

      $this->eventStore = new SqlsrvEventStore(
      new FQCNMessageFactory(),
      $this->connection,
      new SqlsrvSingleStreamStrategy()
    );

      $streamEvent = UserCreated::with(
      ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
      1
    );

      $aggregateId = Uuid::uuid4()->toString();

      $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');
      $streamEvent = $streamEvent->withAddedMetadata('_aggregate_id', $aggregateId);
      $streamEvent = $streamEvent->withAddedMetadata('_aggregate_type', 'user');

      $stream = new Stream(new StreamName('Prooph\Model\User'), new ArrayIterator([$streamEvent]));

      $this->eventStore->create($stream);

      $streamEvent = UsernameChanged::with(
      ['name' => 'John Doe'],
      1
    );

      $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');
      $streamEvent = $streamEvent->withAddedMetadata('_aggregate_id', $aggregateId);
      $streamEvent = $streamEvent->withAddedMetadata('_aggregate_type', 'user');

      $this->eventStore->appendTo(new StreamName('Prooph\Model\User'), new ArrayIterator([$streamEvent]));
  }
}

  {

}
