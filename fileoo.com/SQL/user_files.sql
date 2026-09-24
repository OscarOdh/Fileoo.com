CREATE TABLE `user_files` (
  `file_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `filesize` bigint(20) unsigned NOT NULL,
  `filetype` varchar(100) DEFAULT NULL,
  `thumb_filename` varchar(255) DEFAULT NULL,
  `upload_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_accessed_timestamp` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `description` text DEFAULT NULL,
  PRIMARY KEY (`file_id`),
  UNIQUE KEY `stored_filename` (`stored_filename`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_user_files` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

