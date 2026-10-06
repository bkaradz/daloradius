ALTER TABLE `userbillinfo`
    ADD COLUMN `printed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `batch_id`;
