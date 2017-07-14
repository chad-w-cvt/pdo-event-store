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

namespace Prooph\EventStore\Pdo\Projection;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Iterator;
use PDO;
use PDOException;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception;
use Prooph\EventStore\Pdo\Exception\RuntimeException;
use Prooph\EventStore\Pdo\MariaDbEventStore;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Pdo\SqlsrvEventStore;
use Prooph\EventStore\Projection\ProjectionStatus;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\Projection\ReadModelProjector;
use Prooph\EventStore\StreamName;

final class PdoEventStoreReadModelProjector implements ReadModelProjector
{
    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var PDO
     */
    private $connection;

    /**
     * @var string
     */
    private $name;

    /**
     * @var ReadModel
     */
    private $readModel;

    /**
     * @var string
     */
    private $eventStreamsTable;

    /**
     * @var string
     */
    private $projectionsTable;

    /**
     * @var array
     */
    private $streamPositions = [];

    /**
     * @var int
     */
    private $persistBlockSize;

    /**
     * @var array
     */
    private $state = [];

    /**
     * @var ProjectionStatus
     */
    private $status;

    /**
     * @var callable|null
     */
    private $initCallback;

    /**
     * @var Closure|null
     */
    private $handler;

    /**
     * @var array
     */
    private $handlers = [];

    /**
     * @var boolean
     */
    private $isStopped = false;

    /**
     * @var ?string
     */
    private $currentStreamName = null;

    /**
     * @var int lock timeout in milliseconds
     */
    private $lockTimeoutMs;

    /**
     * @var int
     */
    private $eventCounter = 0;

    /**
     * @var int
     */
    private $sleep;

    /**
     * @var bool
     */
    private $triggerPcntlSignalDispatch;

    /**
     * @var array|null
     */
    private $query;

    public function __construct(
        EventStore $eventStore,
        PDO $connection,
        string $name,
        ReadModel $readModel,
        string $eventStreamsTable,
        string $projectionsTable,
        int $lockTimeoutMs,
        int $persistBlockSize,
        int $sleep,
        bool $triggerPcntlSignalDispatch = false
    ) {
        if ($triggerPcntlSignalDispatch && ! extension_loaded('pcntl')) {
            throw Exception\ExtensionNotLoadedException::withName('pcntl');
        }

        $this->eventStore = $eventStore;
        $this->connection = $connection;
        $this->name = $name;
        $this->readModel = $readModel;
        $this->eventStreamsTable = $eventStreamsTable;
        $this->projectionsTable = $projectionsTable;
        $this->lockTimeoutMs = $lockTimeoutMs;
        $this->persistBlockSize = $persistBlockSize;
        $this->sleep = $sleep;
        $this->status = ProjectionStatus::IDLE();
        $this->triggerPcntlSignalDispatch = $triggerPcntlSignalDispatch;

        while ($eventStore instanceof EventStoreDecorator) {
            $eventStore = $eventStore->getInnerEventStore();
        }

        if (! $eventStore instanceof MariaDbEventStore
            && ! $eventStore instanceof MySqlEventStore
            && ! $eventStore instanceof PostgresEventStore
            && ! $eventStore instanceof SqlsrvEventStore
        ) {
            throw new Exception\InvalidArgumentException('Unknown event store instance given');
        }
    }

    public function init(Closure $callback): ReadModelProjector
    {
        if (null !== $this->initCallback) {
            throw new Exception\RuntimeException('Projection already initialized');
        }

        $callback = Closure::bind($callback, $this->createHandlerContext($this->currentStreamName));

        $result = $callback();

        if (is_array($result)) {
            $this->state = $result;
        }

        $this->initCallback = $callback;

        return $this;
    }

    public function fromStream(string $streamName): ReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        $this->query['streams'][] = $streamName;

        return $this;
    }

    public function fromStreams(string ...$streamNames): ReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        foreach ($streamNames as $streamName) {
            $this->query['streams'][] = $streamName;
        }

        return $this;
    }

    public function fromCategory(string $name): ReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        $this->query['categories'][] = $name;

        return $this;
    }

    public function fromCategories(string ...$names): ReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        foreach ($names as $name) {
            $this->query['categories'][] = $name;
        }

        return $this;
    }

    public function fromAll(): ReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        $this->query['all'] = true;

        return $this;
    }

    public function when(array $handlers): ReadModelProjector
    {
        if (null !== $this->handler || ! empty($this->handlers)) {
            throw new Exception\RuntimeException('When was already called');
        }

        foreach ($handlers as $eventName => $handler) {
            if (! is_string($eventName)) {
                throw new Exception\InvalidArgumentException('Invalid event name given, string expected');
            }

            if (! $handler instanceof Closure) {
                throw new Exception\InvalidArgumentException('Invalid handler given, Closure expected');
            }

            $this->handlers[$eventName] = Closure::bind($handler, $this->createHandlerContext($this->currentStreamName));
        }

        return $this;
    }

    public function whenAny(Closure $handler): ReadModelProjector
    {
        if (null !== $this->handler || ! empty($this->handlers)) {
            throw new Exception\RuntimeException('When was already called');
        }

        $this->handler = Closure::bind($handler, $this->createHandlerContext($this->currentStreamName));

        return $this;
    }

    public function reset(): void
    {
        $this->streamPositions = [];

        $callback = $this->initCallback;

        $this->readModel->reset();

        $this->state = [];

        if (is_callable($callback)) {
            $result = $callback();

            if (is_array($result)) {
                $this->state = $result;
            }
        }

        $sql = <<<EOT
UPDATE $this->projectionsTable SET position = ?, state = ?, status = ?
WHERE name = ?
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([
                json_encode($this->streamPositions),
                json_encode($this->state),
                $this->status->getValue(),
                $this->name,
            ]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }
    }

    public function stop(): void
    {
        $this->isStopped = true;

        $stopProjectionSql = <<<EOT
UPDATE $this->projectionsTable SET status = ? WHERE name = ?;
EOT;
        $statement = $this->connection->prepare($stopProjectionSql);
        try {
            $statement->execute([ProjectionStatus::IDLE()->getValue(), $this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }

        $this->status = ProjectionStatus::IDLE();
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function readModel(): ReadModel
    {
        return $this->readModel;
    }

    public function delete(bool $deleteProjection): void
    {
        $deleteProjectionSql = <<<EOT
DELETE FROM $this->projectionsTable WHERE name = ?;
EOT;
        $statement = $this->connection->prepare($deleteProjectionSql);
        try {
            $statement->execute([$this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }

        if ($deleteProjection) {
            $this->readModel->delete();
        }

        $this->isStopped = true;

        $callback = $this->initCallback;

        $this->state = [];

        if (is_callable($callback)) {
            $result = $callback();

            if (is_array($result)) {
                $this->state = $result;
            }
        }

        $this->streamPositions = [];
    }

    public function run(bool $keepRunning = true): void
    {
        if (null === $this->query
            || (null === $this->handler && empty($this->handlers))
        ) {
            throw new Exception\RuntimeException('No handlers configured');
        }

        switch ($this->fetchRemoteStatus()) {
            case ProjectionStatus::STOPPING():
                $this->stop();

                return;
            case ProjectionStatus::DELETING():
                $this->delete(false);

                return;
            case ProjectionStatus::DELETING_INCL_EMITTED_EVENTS():
                $this->delete(true);

                return;
            case ProjectionStatus::RESETTING():
                $this->reset();
                break;
            default:
                break;
        }

        $this->createProjection();
        $this->acquireLock();

        if (! $this->readModel->isInitialized()) {
            $this->readModel->init();
        }

        $this->prepareStreamPositions();
        $this->load();

        $singleHandler = null !== $this->handler;

        $this->isStopped = false;

        try {
            do {
                foreach ($this->streamPositions as $streamName => $position) {
                    try {
                        $streamEvents = $this->eventStore->load(new StreamName($streamName), $position + 1);
                    } catch (Exception\StreamNotFound $e) {
                        // ignore
                        continue;
                    }

                    if ($singleHandler) {
                        $this->handleStreamWithSingleHandler($streamName, $streamEvents);
                    } else {
                        $this->handleStreamWithHandlers($streamName, $streamEvents);
                    }

                    if ($this->isStopped) {
                        break;
                    }
                }

                if (0 === $this->eventCounter) {
                    usleep($this->sleep);
                    $this->updateLock();
                } else {
                    $this->persist();
                }

                $this->eventCounter = 0;

                if ($this->triggerPcntlSignalDispatch) {
                    pcntl_signal_dispatch();
                }

                switch ($this->fetchRemoteStatus()) {
                    case ProjectionStatus::STOPPING():
                        $this->stop();
                        break;
                    case ProjectionStatus::DELETING():
                        $this->delete(false);
                        break;
                    case ProjectionStatus::DELETING_INCL_EMITTED_EVENTS():
                        $this->delete(true);
                        break;
                    case ProjectionStatus::RESETTING():
                        $this->reset();
                        break;
                    default:
                        break;
                }

                $this->prepareStreamPositions();
            } while ($keepRunning && ! $this->isStopped);
        } finally {
            $this->releaseLock();
        }
    }

    private function fetchRemoteStatus(): ProjectionStatus
    {
        if ($this->eventStore instanceof SqlsrvEventStore)
        {
          $sql = <<<EOT
SELECT TOP 1 status FROM $this->projectionsTable WHERE name = ?;
EOT;
        } else {
          $sql = <<<EOT
SELECT status FROM $this->projectionsTable WHERE name = ? LIMIT 1;
EOT;
        }

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([$this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            $errorCode = $statement->errorCode();
            $errorInfo = $statement->errorInfo()[2];

            throw new RuntimeException(
                "Error $errorCode. Maybe the projection table is not setup?\nError-Info: $errorInfo"
            );
        }

        $result = $statement->fetch(PDO::FETCH_OBJ);

        if (false === $result) {
            return ProjectionStatus::RUNNING();
        }

        return ProjectionStatus::byValue($result->status);
    }

    private function handleStreamWithSingleHandler(string $streamName, Iterator $events): void
    {
        $this->currentStreamName = $streamName;
        $handler = $this->handler;

        foreach ($events as $event) {
            /* @var Message $event */
            $this->streamPositions[$streamName]++;
            $this->eventCounter++;

            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function handleStreamWithHandlers(string $streamName, Iterator $events): void
    {
        $this->currentStreamName = $streamName;

        foreach ($events as $event) {
            /* @var Message $event */
            $this->streamPositions[$streamName]++;

            if (! isset($this->handlers[$event->messageName()])) {
                continue;
            }

            $this->eventCounter++;

            $handler = $this->handlers[$event->messageName()];
            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function createHandlerContext(?string &$streamName)
    {
        return new class($this, $streamName) {
            /**
             * @var ReadModelProjector
             */
            private $projector;

            /**
             * @var ?string
             */
            private $streamName;

            public function __construct(ReadModelProjector $projector, ?string &$streamName)
            {
                $this->projector = $projector;
                $this->streamName = &$streamName;
            }

            public function stop(): void
            {
                $this->projector->stop();
            }

            public function readModel(): ReadModel
            {
                return $this->projector->readModel();
            }

            public function streamName(): ?string
            {
                return $this->streamName;
            }
        };
    }

    private function load(): void
    {
      if ($this->eventStore instanceof SqlsrvEventStore)
      {
        $sql = <<<EOT
SELECT TOP 1 position, state FROM $this->projectionsTable WHERE name = ? ORDER BY no DESC;
EOT;
      } else {
        $sql = <<<EOT
SELECT position, state FROM $this->projectionsTable WHERE name = ? ORDER BY no DESC LIMIT 1;
EOT;
      }


        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([$this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }

        $result = $statement->fetch(PDO::FETCH_OBJ);

        $this->streamPositions = array_merge($this->streamPositions, json_decode($result->position, true));
        $state = json_decode($result->state, true);

        if (! empty($state)) {
            $this->state = $state;
        }
    }

    private function createProjection(): void
    {
        $sql = <<<EOT
INSERT INTO $this->projectionsTable (name, position, state, status, locked_until)
VALUES (?, '{}', '{}', ?, NULL);
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([$this->name, $this->status->getValue()]);
        } catch (PDOException $exception) {
            // we ignore any occurring error here (duplicate projection)
        }
    }

    /**
     * @throws Exception\RuntimeException
     */
    private function acquireLock(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d\TH:i:s.u');

        $lockUntilString = $this->createLockUntilString($now);

        $sql = <<<EOT
UPDATE $this->projectionsTable SET locked_until = ?, status = ? WHERE name = ? AND (locked_until IS NULL OR locked_until < ?);
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([$lockUntilString, ProjectionStatus::RUNNING()->getValue(), $this->name, $nowString]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->rowCount() !== 1) {
            if ($statement->errorCode() !== '00000') {
                $errorCode = $statement->errorCode();
                $errorInfo = $statement->errorInfo()[2];

                throw new Exception\RuntimeException(
                    "Error $errorCode. Maybe the projection table is not setup?\nError-Info: $errorInfo"
                );
            }

            throw new Exception\RuntimeException('Another projection process is already running');
        }

        $this->status = ProjectionStatus::RUNNING();
    }

    private function updateLock(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $lockUntilString = $this->createLockUntilString($now);

        $sql = <<<EOT
UPDATE $this->projectionsTable SET locked_until = ? WHERE name = ?;
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([$lockUntilString, $this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->rowCount() !== 1) {
            if ($statement->errorCode() !== '00000') {
                $errorCode = $statement->errorCode();
                $errorInfo = $statement->errorInfo()[2];

                throw new Exception\RuntimeException(
                    "Error $errorCode. Maybe the projection table is not setup?\nError-Info: $errorInfo"
                );
            }

            throw new Exception\RuntimeException('Unknown error occurred');
        }
    }

    private function releaseLock(): void
    {
        $sql = <<<EOT
UPDATE $this->projectionsTable SET locked_until = NULL, status = ? WHERE name = ?;
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([ProjectionStatus::IDLE()->getValue(), $this->name]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }

        $this->status = ProjectionStatus::IDLE();
    }

    private function persist(): void
    {
        $this->readModel->persist();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $lockUntilString = $this->createLockUntilString($now);

        $sql = <<<EOT
UPDATE $this->projectionsTable SET position = ?, state = ?, locked_until = ? 
WHERE name = ?
EOT;

        $statement = $this->connection->prepare($sql);
        try {
            $statement->execute([
                json_encode($this->streamPositions),
                json_encode($this->state),
                $lockUntilString,
                $this->name,
            ]);
        } catch (PDOException $exception) {
            // ignore and check error code
        }

        if ($statement->errorCode() !== '00000') {
            throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
        }
    }

    private function prepareStreamPositions(): void
    {
        $streamPositions = [];

        if (isset($this->query['all'])) {
            $sql = <<<EOT
SELECT real_stream_name FROM $this->eventStreamsTable WHERE real_stream_name NOT LIKE '$%';
EOT;
            $statement = $this->connection->prepare($sql);
            try {
                $statement->execute();
            } catch (PDOException $exception) {
                // ignore and check error code
            }

            if ($statement->errorCode() !== '00000') {
                throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
            }

            while ($row = $statement->fetch(PDO::FETCH_OBJ)) {
                $streamPositions[$row->real_stream_name] = 0;
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        if (isset($this->query['categories'])) {
            $rowPlaces = implode(', ', array_fill(0, count($this->query['categories']), '?'));

            $sql = <<<EOT
SELECT real_stream_name FROM $this->eventStreamsTable WHERE category IN ($rowPlaces);
EOT;
            $statement = $this->connection->prepare($sql);

            try {
                $statement->execute($this->query['categories']);
            } catch (PDOException $exception) {
                // ignore and check error code
            }

            if ($statement->errorCode() !== '00000') {
                throw RuntimeException::fromStatementErrorInfo($statement->errorInfo());
            }

            while ($row = $statement->fetch(PDO::FETCH_OBJ)) {
                $streamPositions[$row->real_stream_name] = 0;
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        // stream names given
        foreach ($this->query['streams'] as $streamName) {
            $streamPositions[$streamName] = 0;
        }

        $this->streamPositions = array_merge($streamPositions, $this->streamPositions);
    }

    private function createLockUntilString(DateTimeImmutable $from): string
    {
        $micros = (string) ((int) $from->format('u') + ($this->lockTimeoutMs * 1000));

        $secs = substr($micros, 0, -6);

        if ('' === $secs) {
            $secs = 0;
        }

        $resultMicros = substr($micros, -6);

        return $from->modify('+' . $secs .' seconds')->format('Y-m-d\TH:i:s') . '.' . $resultMicros;
    }
}
