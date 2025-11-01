-- Ini BUKAN file .sql untuk di-import.
-- Ini adalah perintah yang HARUS Anda jalankan di phpMyAdmin
-- atau tools database Anda untuk memperbaiki 'storage engine'.
-- Ini akan memperbaiki 'race condition' dan 'transaction'
ALTER TABLE products ENGINE=InnoDB;
-- Ini akan memastikan transaksi order dan webhook berjalan
ALTER TABLE orders ENGINE=InnoDB;
ALTER TABLE order_items ENGINE=InnoDB;
ALTER TABLE payment_attempts ENGINE=InnoDB;
-- Ini akan memastikan data pembelian juga transaksional
ALTER TABLE user_purchase_records ENGINE=InnoDB;
-- (Opsional, tapi direkomendasikan)
ALTER TABLE cart ENGINE=InnoDB;
ALTER TABLE users ENGINE=InnoDB;