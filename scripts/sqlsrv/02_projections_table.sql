CREATE TABLE [dbo].[projections](
	[no] [bigint] IDENTITY(1,1) NOT NULL,
	[name] [varchar](150) NOT NULL,
	[position] [varchar](max) NULL,
	[state] [varchar](max) NULL,
	[status] [varchar](40) NOT NULL,
	[locked_until] [char](26) NULL,
UNIQUE NONCLUSTERED
(
	[name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]