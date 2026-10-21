-- Shree Enterprises - Sale & Purchase System
-- Import in phpMyAdmin (XAMPP): it creates the database `shree_enterprises` itself.
-- Default logins:  admin / admin123   |   staff / staff123

CREATE DATABASE IF NOT EXISTS `shree_enterprises` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `shree_enterprises`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sales_return_items, sales_returns, quotation_items, quotations, cash_sale_items, cash_sales,
  vendor_payments, vendor_commissions, dead_stock, invoice_payments, invoice_items, invoices,
  purchase_payments, purchase_items, purchases, products, subcategories, categories,
  employees, customers, vendors, suppliers, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  reg_date DATE NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  alt_mobile VARCHAR(15) NULL,
  address VARCHAR(255) NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  note VARCHAR(150) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=201;

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  reg_date DATE NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  alt_mobile VARCHAR(15) NULL,
  aadhar_no VARCHAR(12) NULL,
  pan_no VARCHAR(10) NULL,
  address VARCHAR(255) NULL,
  vendor_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cus_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  tie_up_date DATE NOT NULL,
  mobile VARCHAR(15) NOT NULL,
  alt_mobile VARCHAR(15) NULL,
  email VARCHAR(80) NULL,
  aadhar_no VARCHAR(12) NULL,
  pan_no VARCHAR(10) NULL,
  address VARCHAR(255) NULL,
  note VARCHAR(150) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  gender ENUM('Male','Female','Other') NOT NULL,
  dob DATE NOT NULL,
  aadhar_no VARCHAR(12) NULL,
  mobile VARCHAR(15) NOT NULL,
  alt_mobile VARCHAR(15) NULL,
  joining_date DATE NOT NULL,
  experience VARCHAR(50) NULL,
  post VARCHAR(50) NOT NULL,
  email VARCHAR(80) NULL,
  qualification VARCHAR(80) NULL,
  pan_no VARCHAR(10) NULL,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE subcategories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_sub (category_id, name),
  CONSTRAINT fk_sub_cat FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  subcategory_id INT NOT NULL,
  supplier_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  added_date DATE NOT NULL,
  purchase_rate DECIMAL(12,2) NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  stock_qty INT NOT NULL DEFAULT 0,
  unit VARCHAR(20) NOT NULL DEFAULT 'Box',
  UNIQUE KEY uq_prod (subcategory_id, name),
  CONSTRAINT fk_p_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_p_sub FOREIGN KEY (subcategory_id) REFERENCES subcategories(id),
  CONSTRAINT fk_p_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT NOT NULL,
  purchase_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pu_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB AUTO_INCREMENT=501;

CREATE TABLE purchase_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  purchase_price DECIMAL(12,2) NOT NULL,
  sales_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_pi_pu FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL,
  CONSTRAINT fk_pp_pu FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  invoice_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  labour_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  vendor_id INT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_inv_cus FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB AUTO_INCREMENT=601;

CREATE TABLE invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_ii_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE invoice_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL,
  CONSTRAINT fk_ip_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dead_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dead_date DATE NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  purchase_price DECIMAL(12,2) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(150) NULL,
  CONSTRAINT fk_ds_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB AUTO_INCREMENT=801;


-- ---------------------------------------------------------------- v2 modules
CREATE TABLE vendor_commissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  invoice_id INT NOT NULL UNIQUE,
  commission_pct DECIMAL(5,2) NOT NULL,
  base_amount DECIMAL(12,2) NOT NULL,
  commission_amount DECIMAL(12,2) NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vc_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_vc_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE vendor_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  commission_id INT NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  mode VARCHAR(20) NOT NULL,
  note VARCHAR(150) NULL,
  CONSTRAINT fk_vp_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  CONSTRAINT fk_vp_com FOREIGN KEY (commission_id) REFERENCES vendor_commissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cash_sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_date DATE NOT NULL,
  customer_name VARCHAR(80) NULL,
  mobile VARCHAR(15) NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  labour_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  pay_mode VARCHAR(20) NOT NULL DEFAULT 'Cash',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=1001;

CREATE TABLE cash_sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cash_sale_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_csi_cs FOREIGN KEY (cash_sale_id) REFERENCES cash_sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_csi_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE quotations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NULL,
  customer_name VARCHAR(80) NOT NULL,
  mobile VARCHAR(15) NULL,
  quote_date DATE NOT NULL,
  valid_till DATE NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  labour_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_q_cus FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB AUTO_INCREMENT=701;

CREATE TABLE quotation_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_qi_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_qi_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE sales_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_date DATE NOT NULL,
  customer_id INT NOT NULL,
  invoice_id INT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  adjusted_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  refund_mode VARCHAR(20) NULL,
  reason VARCHAR(150) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sr_cus FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_sr_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=901;

CREATE TABLE sales_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_sri_sr FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- seed data
INSERT INTO users (role, username, full_name, password_hash) VALUES
('admin','admin','Shreyas (Owner)','$2y$10$1pZ2oz2TL.Z7so0E2sGbdecP4k3xrCljpja/cROrfeTbZGfWpJinO'),
('staff','staff','Counter Staff','$2y$10$..Ask5TG57UNsVEOHZgR2.7fK4M9bClu/Jv6uRFcJdynW3H8yVKQm');

INSERT INTO categories (id, name) VALUES
(1,'Ceramic Tiles'),(2,'Marble Tiles'),(3,'Porcelain Tiles'),(4,'Metal Tiles'),(5,'Mosaic Tiles'),(6,'Ceiling Tiles');

INSERT INTO subcategories (id, category_id, name) VALUES
(1,1,'Ceramic Floor'),(2,1,'Ceramic Wall'),
(3,2,'Honed marble'),(4,2,'Calibrated marble'),
(5,3,'Polished porcelain tiles'),(6,3,'Glazed porcelain tiles'),
(7,4,'Brass Tiles'),(8,4,'Aluminum Tiles'),
(9,5,'Mosaic marble'),(10,6,'Gypsum tiles');

INSERT INTO suppliers (id,name,tie_up_date,mobile,alt_mobile,email,address,note) VALUES
(1,'Dikshit Malotra','2023-01-20','8845621477','9985475685','dikshit@example.com','Thane','Mumbai stock'),
(2,'Anurag Pattekari','2022-06-10','9187364646',NULL,'anurag@example.com','Karad',NULL),
(3,'Gautam Thakur','2021-08-15','9876501234',NULL,NULL,'Morbi, Gujarat','Porcelain'),
(4,'Kishan Sharma','2020-02-01','9812300456',NULL,NULL,'Jaipur, Rajasthan',NULL);

INSERT INTO vendors (id,name,reg_date,mobile,address,commission_pct) VALUES
(201,'Sunil Mestri','2024-01-05','9822012345','Kolhapur',3.00);
ALTER TABLE vendors AUTO_INCREMENT = 202;

INSERT INTO customers (id,name,reg_date,mobile,alt_mobile,address) VALUES
(101,'Rahul Saxena','2023-04-08','9876543210',NULL,'Kolhapur'),
(102,'Priya Patil','2023-04-10','9823456789',NULL,'Radhanagari'),
(103,'Rajesh Milind Sharma','2023-04-03','9147856225',NULL,'Shivaji Chawk, Vidyanagar, Karad');
ALTER TABLE customers AUTO_INCREMENT = 104;

INSERT INTO products (id,category_id,subcategory_id,supplier_id,name,added_date,purchase_rate,sales_rate,stock_qty,unit) VALUES
(1,1,1,1,'Asian(3*3)','2023-01-10',420,540,60,'Box'),
(2,1,2,1,'Kajaria(3*3)','2023-01-10',450,610,45,'Box'),
(3,2,3,1,'Nitco(8*8)','2023-01-12',500,650,30,'Box'),
(4,2,4,2,'Icon(3*3)','2023-01-12',480,600,25,'Box'),
(5,3,5,3,'Qutone(8*8)','2023-01-15',450,640,53,'Box'),
(6,3,5,3,'Qutone(4*4)','2023-01-15',480,680,50,'Box'),
(7,3,5,4,'RAK(8*8)','2023-01-15',560,780,50,'Box'),
(8,4,7,2,'Jonson(3*3)','2023-01-18',450,630,40,'Box'),
(9,6,10,1,'Rock (4*4)','2023-01-10',500,800,1,'Box'),
(10,5,9,2,'Somany(3*3)','2023-01-20',450,650,35,'Box');
