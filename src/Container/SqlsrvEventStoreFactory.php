<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:04 AM
 */

declare(strict_types=1);

namespace Prooph\EventStore\Pdo\Container;

use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\ActionEventEmitterEventStore;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\TransactionalActionEventEmitterEventStore;

final class SqlsrvEventStoreFactory extends AbstractEventStoreFactory
{
  protected function createActionEventEmitterEventStore(EventStore $eventStore): ActionEventEmitterEventStore
  {
    return new TransactionalActionEventEmitterEventStore(
      $eventStore,
      new ProophActionEventEmitter([
        TransactionalActionEventEmitterEventStore::EVENT_APPEND_TO,
        TransactionalActionEventEmitterEventStore::EVENT_CREATE,
        TransactionalActionEventEmitterEventStore::EVENT_LOAD,
        TransactionalActionEventEmitterEventStore::EVENT_LOAD_REVERSE,
        TransactionalActionEventEmitterEventStore::EVENT_DELETE,
        TransactionalActionEventEmitterEventStore::EVENT_HAS_STREAM,
        TransactionalActionEventEmitterEventStore::EVENT_FETCH_STREAM_METADATA,
        TransactionalActionEventEmitterEventStore::EVENT_UPDATE_STREAM_METADATA,
        TransactionalActionEventEmitterEventStore::EVENT_FETCH_STREAM_NAMES,
        TransactionalActionEventEmitterEventStore::EVENT_FETCH_STREAM_NAMES_REGEX,
        TransactionalActionEventEmitterEventStore::EVENT_FETCH_CATEGORY_NAMES,
        TransactionalActionEventEmitterEventStore::EVENT_FETCH_CATEGORY_NAMES_REGEX,
        TransactionalActionEventEmitterEventStore::EVENT_BEGIN_TRANSACTION,
        TransactionalActionEventEmitterEventStore::EVENT_COMMIT,
        TransactionalActionEventEmitterEventStore::EVENT_ROLLBACK,
      ])
    );
  }

  protected function eventStoreClassName(): string
  {
    return SqlsrvEventStore::class;
  }

  public function defaultOptions(): iterable
  {
    return [
      'load_batch_size' => 1000,
      'event_streams_table' => 'event_streams',
      'message_factory' => FQCNMessageFactory::class,
      'wrap_action_event_emitter' => true,
      'metadata_enrichers' => [],
      'plugins' => [],
    ];
  }
}