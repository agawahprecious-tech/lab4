-- Lab 04 - E-Commerce Product Entry
-- Import with: mysql -u root -p < database.sql

CREATE DATABASE IF NOT EXISTS lab04_product_entry
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab04_product_entry;

CREATE TABLE IF NOT EXISTS products (
  product_id     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_name   VARCHAR(100)  NOT NULL,
  sku            VARCHAR(30)   NOT NULL,
  category       VARCHAR(40)   NOT NULL,
  unit_price_xaf DECIMAL(12,2) NOT NULL,
  quantity       INT UNSIGNED  NOT NULL DEFAULT 0,
  description    VARCHAR(1000) NULL,
  image_file     VARCHAR(40)   NULL,               -- server-generated file name only, e.g. 9f2c...e1.jpg
  is_available   TINYINT(1)    NOT NULL DEFAULT 0,
  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id),
  UNIQUE KEY uq_products_sku (sku),
  CONSTRAINT chk_price CHECK (unit_price_xaf > 0)
) ENGINE=InnoDB;

-- Verification queries
-- SELECT product_id, sku, product_name, category, unit_price_xaf, quantity, image_file FROM products ORDER BY product_id DESC;
-- SELECT sku, COUNT(*) FROM products GROUP BY sku HAVING COUNT(*) > 1;   -- 0 rows
