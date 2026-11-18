CREATE TABLE IF NOT EXISTS `#__gitinsync_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(32) NOT NULL,
  `item_id` int unsigned NOT NULL,
  `uuid` char(36) NOT NULL,
  `dirty` tinyint unsigned NOT NULL DEFAULT 0,
  `excluded` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_type_item` (`type`, `item_id`),
  UNIQUE KEY `idx_type_uuid` (`type`, `uuid`),
  KEY `idx_dirty` (`dirty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__gitinsync_state` (
  `name` varchar(191) NOT NULL,
  `value` mediumtext NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__gitinsync_objects` (
  `id` char(40) NOT NULL,
  `type` varchar(10) NOT NULL,
  `data` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__gitinsync_operations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `branch` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `data` longtext NOT NULL,
  `message` text NOT NULL,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__gitinsync_pushed` (
  `id` char(40) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
