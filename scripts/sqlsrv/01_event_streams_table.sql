CREATE TABLE [event_streams](
	[no] [bigint] IDENTITY(1,1) NOT NULL,
	[real_stream_name] [nvarchar](150) NOT NULL,
	[stream_name] [nvarchar](41) NOT NULL,
	[metadata] [nvarchar](max) NULL,
	[category] [nvarchar](150) NULL,
	[locked_until] [char](26) NULL
UNIQUE NONCLUSTERED
(
	[stream_name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]