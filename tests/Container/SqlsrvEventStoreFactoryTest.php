<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 10:09 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Container;

use PHPUnit\Framework\TestCase;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\ActionEventEmitterEventStore;
use Prooph\EventStore\Exception\ConfigurationException;
use Prooph\EventStore\Metadata\MetadataEnricher;
use Prooph\EventStore\Pdo\Container\SqlsrvEventStoreFactory;
use Prooph\EventStore\Pdo\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Plugin\Plugin;
use ProophTest\EventStore\Pdo\TestUtil;
use Psr\Container\ContainerInterface;

/**
 * @group sqlsrv
 */
final class SqlsrvEventStoreFactoryTest extends TestCase
{
  /**
   * @test
   */
  public function it_creates_adapter_via_connection_service(): void
  {
    $config['prooph']['event_store']['default'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
      'wrap_action_event_emitter' => false,
    ];

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get('config')->willReturn($config)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $factory = new SqlsrvEventStoreFactory();
    $eventStore = $factory($container->reveal());

    $this->assertInstanceOf(SqlsrvEventStore::class, $eventStore);
  }

  /**
   * @test
   */
  public function it_wraps_action_event_emitter(): void
  {
    $config['prooph']['event_store']['custom'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
    ];

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('config')->willReturn($config)->shouldBeCalled();
    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $eventStoreName = 'custom';
    $eventStore = SqlsrvEventStoreFactory::$eventStoreName($container->reveal());

    $this->assertInstanceOf(ActionEventEmitterEventStore::class, $eventStore);
  }

  /**
   * @test
   */
  public function it_injects_plugins(): void
  {
    $config['prooph']['event_store']['custom'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
      'plugins' => ['plugin'],
    ];

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('config')->willReturn($config)->shouldBeCalled();
    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $featureMock = $this->getMockForAbstractClass(Plugin::class);
    $featureMock->expects($this->once())->method('attachToEventStore');

    $container->get('plugin')->willReturn($featureMock);

    $eventStoreName = 'custom';
    $eventStore = SqlsrvEventStoreFactory::$eventStoreName($container->reveal());

    $this->assertInstanceOf(ActionEventEmitterEventStore::class, $eventStore);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_plugin_configured(): void
  {
    $this->expectException(ConfigurationException::class);
    $this->expectExceptionMessage('Plugin plugin does not implement the Plugin interface');

    $config['prooph']['event_store']['custom'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
      'plugins' => ['plugin'],
    ];

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('config')->willReturn($config)->shouldBeCalled();
    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $container->get('plugin')->willReturn('notAValidPlugin');

    $eventStoreName = 'custom';
    SqlsrvEventStoreFactory::$eventStoreName($container->reveal());
  }

  /**
   * @test
   */
  public function it_injects_metadata_enrichers(): void
  {
    $config['prooph']['event_store']['custom'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
      'metadata_enrichers' => ['metadata_enricher1', 'metadata_enricher2'],
    ];

    $metadataEnricher1 = $this->prophesize(MetadataEnricher::class);
    $metadataEnricher2 = $this->prophesize(MetadataEnricher::class);

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('config')->willReturn($config);
    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $container->get('metadata_enricher1')->willReturn($metadataEnricher1->reveal());
    $container->get('metadata_enricher2')->willReturn($metadataEnricher2->reveal());

    $eventStoreName = 'custom';
    $eventStore = SqlsrvEventStoreFactory::$eventStoreName($container->reveal());

    $this->assertInstanceOf(ActionEventEmitterEventStore::class, $eventStore);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_metadata_enricher_configured(): void
  {
    $this->expectException(ConfigurationException::class);
    $this->expectExceptionMessage('Metadata enricher foobar does not implement the MetadataEnricher interface');

    $config['prooph']['event_store']['custom'] = [
      'connection' => 'my_connection',
      'persistence_strategy' => PersistenceStrategy\SqlsrvAggregateStreamStrategy::class,
      'metadata_enrichers' => ['foobar'],
    ];

    $connection = TestUtil::getConnection();

    $container = $this->prophesize(ContainerInterface::class);

    $container->get('config')->willReturn($config);
    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(FQCNMessageFactory::class)->willReturn(new FQCNMessageFactory())->shouldBeCalled();
    $container->get(PersistenceStrategy\SqlsrvAggregateStreamStrategy::class)->willReturn(new PersistenceStrategy\SqlsrvAggregateStreamStrategy())->shouldBeCalled();

    $container->get('foobar')->willReturn('foobar');

    $eventStoreName = 'custom';
    SqlsrvEventStoreFactory::$eventStoreName($container->reveal());
  }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_container_given(): void
  {
    $this->expectException(InvalidArgumentException::class);

    $eventStoreName = 'custom';
    SqlsrvEventStoreFactory::$eventStoreName('invalid container');
  }
}
