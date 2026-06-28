<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't support ALTER COLUMN, so we recreate the table
        // with 'reserved' added to the status CHECK constraint.
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE shop_orders_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_number VARCHAR,
                shop_clinic_id INTEGER,
                shop_order_model_id INTEGER,
                status VARCHAR NOT NULL DEFAULT 'new'
                    CHECK (status IN ('new','reserved','confirmed','processing','in_delivery','completed','cancelled')),
                payment_status VARCHAR NOT NULL DEFAULT 'pending',
                wallet_applied NUMERIC NOT NULL DEFAULT 0,
                rebate_amount NUMERIC NOT NULL DEFAULT 0,
                rebate_credited_at DATETIME,
                subtotal NUMERIC NOT NULL DEFAULT 0,
                cost_subtotal NUMERIC NOT NULL DEFAULT 0,
                surcharge_amount NUMERIC NOT NULL DEFAULT 0,
                total NUMERIC NOT NULL DEFAULT 0,
                currency VARCHAR NOT NULL DEFAULT 'MKD',
                delivery_contact VARCHAR,
                delivery_phone VARCHAR,
                delivery_email VARCHAR,
                delivery_city VARCHAR,
                delivery_address VARCHAR,
                delivery_notes TEXT,
                placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                requested_delivery_date DATE,
                next_recurrence_date DATE,
                cancelled_at DATETIME,
                completed_at DATETIME,
                admin_note TEXT,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (shop_clinic_id) REFERENCES shop_clinics(id) ON DELETE SET NULL,
                FOREIGN KEY (shop_order_model_id) REFERENCES shop_order_models(id) ON DELETE SET NULL
            )
        ");

        DB::statement("
            INSERT INTO shop_orders_new
            SELECT
                id, order_number, shop_clinic_id, shop_order_model_id, status,
                payment_status, wallet_applied, rebate_amount, rebate_credited_at,
                subtotal, cost_subtotal, surcharge_amount, total, currency,
                delivery_contact, delivery_phone, delivery_email, delivery_city,
                delivery_address, delivery_notes, placed_at, requested_delivery_date,
                next_recurrence_date, cancelled_at, completed_at, admin_note,
                created_at, updated_at
            FROM shop_orders
        ");

        DB::statement('DROP TABLE shop_orders');
        DB::statement('ALTER TABLE shop_orders_new RENAME TO shop_orders');

        // Recreate indexes
        DB::statement('CREATE INDEX shop_orders_shop_clinic_id_index ON shop_orders (shop_clinic_id)');
        DB::statement('CREATE INDEX shop_orders_status_index ON shop_orders (status)');
        DB::statement('CREATE INDEX shop_orders_placed_at_index ON shop_orders (placed_at)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Reverse: remove 'reserved' from allowed statuses
        // First update any reserved orders back to 'new'
        DB::statement("UPDATE shop_orders SET status = 'new' WHERE status = 'reserved'");

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement("
            CREATE TABLE shop_orders_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_number VARCHAR,
                shop_clinic_id INTEGER,
                shop_order_model_id INTEGER,
                status VARCHAR NOT NULL DEFAULT 'new'
                    CHECK (status IN ('new','confirmed','processing','in_delivery','completed','cancelled')),
                payment_status VARCHAR NOT NULL DEFAULT 'pending',
                wallet_applied NUMERIC NOT NULL DEFAULT 0,
                rebate_amount NUMERIC NOT NULL DEFAULT 0,
                rebate_credited_at DATETIME,
                subtotal NUMERIC NOT NULL DEFAULT 0,
                cost_subtotal NUMERIC NOT NULL DEFAULT 0,
                surcharge_amount NUMERIC NOT NULL DEFAULT 0,
                total NUMERIC NOT NULL DEFAULT 0,
                currency VARCHAR NOT NULL DEFAULT 'MKD',
                delivery_contact VARCHAR,
                delivery_phone VARCHAR,
                delivery_email VARCHAR,
                delivery_city VARCHAR,
                delivery_address VARCHAR,
                delivery_notes TEXT,
                placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                requested_delivery_date DATE,
                next_recurrence_date DATE,
                cancelled_at DATETIME,
                completed_at DATETIME,
                admin_note TEXT,
                created_at DATETIME,
                updated_at DATETIME
            )
        ");

        DB::statement("INSERT INTO shop_orders_old SELECT * FROM shop_orders");
        DB::statement('DROP TABLE shop_orders');
        DB::statement('ALTER TABLE shop_orders_old RENAME TO shop_orders');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
