# Data Model

## Core Entities

### users
Primary user table. Authenticated via username + password.
- `uuid`, `username` (unique), `name`, `email`, `phone`, `password`, `is_active`
- Soft deletes enabled

### roles
Static role definitions with tier classification.
- `name`, `slug` (unique), `tier` (internal/dealer/oem/driver), `description`

### user_roles
Many-to-many pivot between users and roles.
- `user_id`, `role_id` (unique composite)

### companies
Dealership / OEM entities.
- `uuid`, `name`, `normalized_name` (unique, auto-generated), `address`, `vat_number`, `billing_email`, `phone`, `is_active`

### company_users
Links dealer users to their company.
- `company_id`, `user_id` (unique composite)

### locations
Company-owned pickup/delivery addresses with GPS coordinates and customer contact info.
- `uuid`, `company_id` (nullable FK), `company_name`, `is_private`, `address`, `city`, `province`, `latitude`, `longitude`
- Customer: `customer_name`, `customer_contact`, `customer_phone`, `customer_email`
- `is_active`, soft deletes

### vehicle_classes
Configurable vehicle classification list.
- `name`, `description`, `is_active`

### brands
Vehicle manufacturers (pre-seeded).
- `name` (unique), `is_active`

### vehicle_models
Models belonging to brands, auto-suggest library.
- `brand_id`, `name`, `is_active`

### transport_routes
Composite route definition: origin + destination + vehicle class.
- `origin_location_id`, `destination_location_id`, `vehicle_class_id`, `base_price`, `is_active`

### transport_jobs
Core entity. Stores both transport and yard work jobs.
- Job identification: `uuid`, `job_number`, `job_type`, `status`
- Relationships: `company_id`, `created_by_user_id`, `driver_user_id`, `transport_route_id`
- Transport fields: `pickup_location_id`, `delivery_location_id`, `vehicle_class_id`, `brand_id`, `model_name`, `vin`, `scheduled_date`, `scheduled_ready_time`
- PO fields: `po_number`, `po_amount` (optional at creation, uploaded via detail page), `po_verified`, `po_verified_at`, `po_verified_by`
- Yard fields: `yard_location_id`, `drivers_required`, `hours_required`, `hourly_rate`
- Financial (customer): `base_transport_price`, `delivery_fuel_price`, `penalty_amount`, `credit_amount`, `vat_amount`, `total_sell_price`
- Financial (internal): `cost_fuel`, `cost_tolls`, `cost_driver`, `cost_accommodation`, `cost_other`, `total_cost`, `gross_profit`, `margin_percent`
- Timing: multiple timestamp fields for each status transition

### job_events
Driver workflow events with GPS and sync tracking.
- `job_id`, `user_id`, `event_type`, `event_at`, `latitude`, `longitude`, `notes`, `synced_at`, `client_uuid`

### job_documents
Uploaded files (PO, POD, fuel slips, photos).
- `job_id`, `uploaded_by_user_id`, `category`, `disk`, `path`, `original_filename`, `mime_type`, `size_bytes`, `file_hash`

### invoices
Generated invoices per job.
- `uuid`, `job_id`, `company_id`, `invoice_number`, `subtotal`, `vat_amount`, `total`, `status`, `generated_at`

### credit_notes
Performance credit notes per company per month.
- `uuid`, `company_id`, `invoice_id`, `credit_number`, `amount`, `reason`, `period_month`, `period_year`

### cancellations
Job cancellation records with penalty tracking.
- `job_id`, `cancelled_by_user_id`, `reason`, `penalty_amount`, `penalty_overridden`, `override_reason`, `is_late`

### system_settings
Key-value configuration store with type casting and caching.
- `key`, `value`, `type`, `description`

### audit_logs
Immutable append-only audit trail. Updates and deletes are blocked at the model level.
- `actor_user_id`, `actor_roles_snapshot`, `action_type`, `entity_type`, `entity_id`, `before_json`, `after_json`, `reason`, `ip_address`, `user_agent`, `created_at`

### notification_logs
Email/notification delivery tracking.
- `to_email`, `to_user_id`, `subject`, `channel`, `template`, `entity_type`, `entity_id`, `sent_at`, `status`, `error_message`
