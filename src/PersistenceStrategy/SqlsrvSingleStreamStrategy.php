<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:46 AM
 */

declare(strict_types=1);

namespace Prooph\EventStore\Pdo\PersistenceStrategy;

use Iterator;
use Prooph\EventStore\Pdo\Exception;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\StreamName;

final class SqlsrvSingleStreamStrategy implements PersistenceStrategy
{
  /**
   * @param string $tableName
   * @return string[]
   */
  public function createSchema(string $tableName): array
  {
    $statement = <<<EOT
CREATE TABLE $tableName(
	[no] [bigint] IDENTITY(1,1) NOT NULL,
	[event_id] [nvarchar](36) NOT NULL,
	[event_name] [nvarchar](100) NOT NULL,
	[payload] [nvarchar](max) NOT NULL,
	[metadata] [nvarchar](max) NOT NULL,
	[created_at] [datetime2](6) NOT NULL,
	[aggregate_version] AS (CONVERT([bigint],json_value([metadata],'$._aggregate_version'))) PERSISTED NOT NULL,
	[aggregate_id] AS (CONVERT([nvarchar](36),json_value([metadata],'$._aggregate_id'))) PERSISTED NOT NULL,
	[aggregate_type] AS (CONVERT([nvarchar](150),json_value([metadata],'$._aggregate_type'))) PERSISTED NOT NULL,
UNIQUE NONCLUSTERED 
(
	[aggregate_version] ASC,
	[aggregate_id] ASC,
	[aggregate_type] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY],
UNIQUE NONCLUSTERED 
(
	[event_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
EOT;

    return [$statement];
  }

  public function columnNames(): array
  {
    return [
      'event_id',
      'event_name',
      'payload',
      'metadata',
      'created_at',
    ];
  }

  public function prepareData(Iterator $streamEvents): array
  {
    $data = [];

    foreach ($streamEvents as $event) {
      if (! isset($event->metadata()['_aggregate_version'])) {
        throw new Exception\RuntimeException('_aggregate_version is missing in metadata');
      }

//      $data[] = $event->metadata()['_aggregate_version'];
      $data[] = $event->uuid()->toString();
      $data[] = $event->messageName();
      $data[] = json_encode($event->payload());
      $data[] = json_encode($event->metadata());
      $data[] = $event->createdAt()->format('Y-m-d\TH:i:s.u');
    }

    return $data;
  }

  public function generateTableName(StreamName $streamName): string
  {
    return '_' . sha1($streamName->toString());
  }
}
