-- Samarth Tiles v2 upgrade - run ONCE in phpMyAdmin on your EXISTING shree_enterprises database (keeps all current data).
-- Requires MariaDB (XAMPP default). Select the shree_enterprises database first, then Import / run this file.
-- Do NOT run it on a database freshly imported from the new shree_enterprises.sql (that already has everything).

CREATE TABLE IF NOT EXISTS vendors (
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

ALTER TABLE customers ADD COLUMN IF NOT EXISTS vendor_id INT NULL;
ALTER TABLE customers ADD CONSTRAINT fk_cus_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id);

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS labour_charges DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER gst_pct;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS transport_charges DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER labour_charges;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS vendor_id INT NULL AFTER transport_charges;
ALTER TABLE invoices ADD CONSTRAINT fk_inv_ven FOREIGN KEY (vendor_id) REFERENCES vendors(id);

CREATE TABLE IF NOT EXISTS vendor_commissions (
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

CREATE TABLE IF NOT EXISTS vendor_payments (
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

CREATE TABLE IF NOT EXISTS cash_sales (
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

CREATE TABLE IF NOT EXISTS cash_sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cash_sale_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_csi_cs FOREIGN KEY (cash_sale_id) REFERENCES cash_sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_csi_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quotations (
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

CREATE TABLE IF NOT EXISTS quotation_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sales_rate DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_qi_q FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_qi_pr FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_returns (
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

CREATE TABLE IF NOT EXISTS sales_return_items (
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
