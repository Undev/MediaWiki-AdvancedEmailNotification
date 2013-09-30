CREATE TABLE `watchlist_subpages` (
  `wls_user`      INT(10)        NOT NULL,
  `wls_namespace` INT(11) DEFAULT '0',
  `wls_category`  VARBINARY(255) NOT NULL,
  `wls_title`     VARBINARY(255) NOT NULL,
  UNIQUE KEY `wls_user` (`wls_user`, `wls_category`, `wls_title`)
)
  ENGINE =InnoDB
  DEFAULT CHARSET =utf8;

CREATE
VIEW `watchlist_union` AS
  SELECT
    wl_user,
    wl_title,
    wl_namespace
  FROM
      `watchlist`
      LEFT JOIN `watchlist_subpages` ON wl_title = wls_category
                                        AND wl_user = wls_user

  UNION DISTINCT

  SELECT
    wls_user,
    wls_title,
    wls_namespace
  FROM
      `watchlist`
      LEFT JOIN `watchlist_subpages` ON wl_title = wls_category
                                        AND wl_user = wls_user;

CREATE
VIEW `watchlist_extended` AS
  SELECT
    `w1`.`wl_user`                  AS `wl_user`,
    `w1`.`wl_title`                 AS `wl_title`,
    `w1`.`wl_namespace`             AS `wl_namespace`,
    `w2`.`wl_notificationtimestamp` AS `wl_notificationtimestamp`
  FROM
    (`watchlist_union` `w1`
       LEFT JOIN `watchlist` `w2`
         ON (((`w1`.`wl_user` = `w2`.`wl_user`)
              AND (`w1`.`wl_title` = `w2`.`wl_title`)
              AND (`w1`.`wl_namespace` = `w2`.`wl_namespace`))));