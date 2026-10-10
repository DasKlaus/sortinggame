DROP TABLE IF EXISTS `player`;
DROP TABLE IF EXISTS `game`;
DROP TABLE IF EXISTS `membership`;
DROP TABLE IF EXISTS `category`;

CREATE TABLE `category` (
	`id` int(10) NOT NULL AUTO_INCREMENT,
	`language` varchar(10) NOT NULL,
	`name` varchar(255) NOT NULL, -- "air…", "Rhymes with “know”"
	`type` varchar(16) NOT NULL, -- compound, rhyme, anagram, meaning, origin, spelling, …
	`parent_id` int(10) DEFAULT NULL, -- e.g. hypernym, collecting categories with considerable overlap: categories with the same parent never share a board
	`playable` tinyint(1) NOT NULL DEFAULT 1, -- 0: never picked, only rules boards out ("Also a surname")
	PRIMARY KEY (`id`),
	KEY (`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership` (
	`category_id` int(10) NOT NULL,
	`item` varchar(255) NOT NULL COLLATE utf8mb4_bin, -- case- and accent-sensitive: "Polish" ≠ "polish", "die" ≠ "Die"
	`obscure` tinyint(1) NOT NULL DEFAULT 0, -- true, but not used as a link or group member; still rules boards out (the *connection* is obscure, not necessarily the item)
	PRIMARY KEY (`category_id`, `item`),
	KEY (`item`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `game` (
	`seed` varchar(16) NOT NULL, -- random board id, e.g. 3f9a0c1e; a single-player board is a game per player, copied from the first one
	`language` varchar(10) NOT NULL,
	`mode` varchar(8) NOT NULL, -- sorting
	`types` json NOT NULL, -- category types selected on creation: ["compound","rhyme"]
	`size` tinyint(3) NOT NULL, -- number of categories, and of words per category
	`items` json NOT NULL, -- the words in board order with their category, never sent to the client: [["port","air…"],...]
	`board` json NOT NULL, -- per word index of items, the previous word in its cluster, -1 for a cluster's first word: [-1,0,-1,-1,2,...]
	`version` int(10) NOT NULL DEFAULT 0,
	`multiplayer` tinyint(1) NOT NULL DEFAULT 0, -- all players share one game and its board
	`created_by` bigint(20) NOT NULL,
	`created_at` datetime NOT NULL,
	`finished_at` datetime DEFAULT NULL,
	PRIMARY KEY (`seed`, `created_by`)
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
