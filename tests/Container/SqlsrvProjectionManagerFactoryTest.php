<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 10:10 AM
 */

declare(strict_types=1);

namespace ProophTest\EventStore\Pdo\Container;

use PHPUnit\Framework\TestCase;
use Prooph\Common\Messaging\MessageFactory;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Pdo\Container\SqlsrvProjectionManagerFactory;
use Prooph\EventStore\Pdo\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\HasQueryHint;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Pdo\Projection\SqlsrvProjectionManager;
use ProophTest\EventStore\Pdo\TestUtil;
use Psr\Container\ContainerInterface;

/**
 * @group sqlsrv
 */
class SqlsrvProjectionManagerFactoryTest extends TestCase
{
  /**
   * @test
   */
  public function it_creates_service(): void
  {
    $config['prooph']['projection_manager']['default'] = [
      'connection' => 'my_connection',
    ];

    $connection = TestUtil::getConnection();

    $messageFactory = $this->prophesize(MessageFactory::class);
    $persistenceStrategy = $this->prophesize(PersistenceStrategy::class);
    $persistenceStrategy->willImplement(HasQueryHint::class);

    $container = $this->prophesize(ContainerInterface::class);
    $eventStore = new SqlsrvEventStore(
      $messageFactory->reveal(),
      TestUtil::getConnection(),
      $persistenceStrategy->reveal()
    );

    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
    $container->get('config')->willReturn($config)->shouldBeCalled();

    $factory = new SqlsrvProjectionManagerFactory();
    $projectionManager = $factory($container->reveal());

    $this->assertInstanceOf(SqlsrvProjectionManager::class, $projectionManager);
  }

  /**
   * @test
   */
  public function it_creates_service_via_callstatic(): void
  {
    $config['prooph']['projection_manager']['default'] = [
      'connection' => 'my_connection',
    ];

    $connection = TestUtil::getConnection();

    $messageFactory = $this->prophesize(MessageFactory::class);
    $persistenceStrategy = $this->prophesize(PersistenceStrategy::class);
    $persistenceStrategy->willImplement(HasQueryHint::class);

    $container = $this->prophesize(ContainerInterface::class);
    $eventStore = new SqlsrvEventStore(
      $messageFactory->reveal(),
      TestUtil::getConnection(),
      $persistenceStrategy->reveal()
    );

    $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
    $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
    $container->get('config')->willReturn($config)->shouldBeCalled();

    $name = 'default';
    $pdo = SqlsrvProjectionManagerFactory::$name($container->reveal());

    $this->assertInstanceOf(SqlsrvProjectionManager::class, $pdo);
  }

  /**
   * @test
   */
  public function it_throws_exception_when_invalid_container_given(): void
  {
    $this->expectException(InvalidArgumentException::class);

    $projectionName = 'custom';
    SqlsrvProjectionManagerFactory::$projectionName('invalid container');
  }
}
