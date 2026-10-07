DROP TABLE IF EXISTS `player`;
DROP TABLE IF EXISTS `game`;

CREATE TABLE `game` (
	`seed` varchar(16) NOT NULL, -- e.g. en25-k3x9q
	`language` varchar(10) NOT NULL,
	`size` tinyint(3) NOT NULL,
	`board` json NOT NULL, -- per word index of the seed's shuffled list, the previous word in its cluster, -1 for a cluster's first word: [-1,0,-1,-1,2,...]
	`version` int(10) NOT NULL DEFAULT 0,
	`created_by` bigint(20) NOT NULL,
	`created_at` datetime NOT NULL,
	`finished_at` datetime DEFAULT NULL,
	PRIMARY KEY (`seed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `player` (
	`seed` varchar(16) NOT NULL,
	`user_id` bigint(20) NOT NULL,
	`display_name` varchar(255) NOT NULL,
	`score` int(10) NOT NULL DEFAULT 0,
	`mistakes` int(10) NOT NULL DEFAULT 0,
	`layout` varchar(8) NOT NULL DEFAULT 'grid',
	`slots` json DEFAULT NULL, -- word indexes in display order, a slot whose word has a previous one is a hole; null for the initial 0..n²-1
	PRIMARY KEY (`seed`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
