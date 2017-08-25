DROP TABLE IF EXISTS `kufr_game`;
CREATE TABLE `kufr_game` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) COLLATE utf8_czech_ci NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `result_points` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;


DROP TABLE IF EXISTS `kufr_game_levels`;
CREATE TABLE `kufr_game_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `thing_id` int(11) DEFAULT NULL,
  `guesses` int(11) NOT NULL DEFAULT '0',
  `unhide` text COLLATE utf8_czech_ci,
  `done` tinyint(4) NOT NULL DEFAULT '0',
  `used_hint` tinyint(4) DEFAULT '0',
  `points` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `thing_id` (`thing_id`),
  KEY `game_id` (`game_id`),
  CONSTRAINT `kufr_game_levels_ibfk_3` FOREIGN KEY (`thing_id`) REFERENCES `kufr_game_things` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `kufr_game_levels_ibfk_4` FOREIGN KEY (`game_id`) REFERENCES `kufr_game` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;


DROP TABLE IF EXISTS `kufr_game_things`;
CREATE TABLE `kufr_game_things` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text COLLATE utf8_czech_ci NOT NULL,
  `path` varchar(255) COLLATE utf8_czech_ci NOT NULL,
  `hint` text COLLATE utf8_czech_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;
