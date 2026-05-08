-- Tambah stock_cycle_id pada order_items agar pending order bisa ikut siklus (reset limit).
-- Jalankan sekali saja.

ALTER TABLE order_items
ADD COLUMN stock_cycle_id INT NOT NULL DEFAULT 1 AFTER product_id;
