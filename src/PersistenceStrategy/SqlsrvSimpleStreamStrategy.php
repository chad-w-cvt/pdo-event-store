<?php
/**
 * Created by PhpStorm.
 * User: chadw
 * Date: 7/13/2017
 * Time: 9:44 AM
 */

declare(strict_types=1);

namespace Prooph\EventStore\Pdo\PersistenceStrategy;

use Iterator;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\StreamName;

final class SqlsrvSimpleStreamStrategy implements PersistenceStrategy
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
	[created_at] [datetime2](6) NOT NULL
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
