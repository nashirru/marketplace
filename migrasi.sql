
ALTER TABLE `orders`
ADD COLUMN `midtrans_transaction_id` VARCHAR(255) NULL AFTER `total`ADD COLUMN `midtrans_payment_type` VARCHAR(100) DEFAULT NULL,
ADD COLUMN `midtrans_status` VARCHAR(50) DEFAULT NULL,
ADD COLUMN `expiry_time` DATETIME DEFAULT NULL COMMENT 'Waktu kedaluwarsa dari Midtrans',
ADD COLUMN `cancel_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `status`;

ALTER TABLE `orders`
ADD KEY `idx_status` (`status`);

CREATE TABLE `payment_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `attempt_order_number` VARCHAR(100) NOT NULL,
  `snap_token` VARCHAR(255) NOT NULL,
  `status` ENUM('pending','success','failure','expired') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_order_number` (`attempt_order_number`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE `payment_attempts`
ADD CONSTRAINT `fk_payment_attempt_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;
