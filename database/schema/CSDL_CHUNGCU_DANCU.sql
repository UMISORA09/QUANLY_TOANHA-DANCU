/* ============================================================================
   CSDL CHUNG CƯ & DÂN CƯ - MICROSOFT SQL SERVER / T-SQL
   Chuyển đổi từ bộ PostgreSQL; chạy trên SQL Server 2017+.
   Khuyến nghị: tạo database trước, sau đó chạy toàn bộ file bằng SSMS/sqlcmd.
   Các ENUM PostgreSQL được biểu diễn bằng VARCHAR(50); giá trị hợp lệ giữ
   nguyên như dữ liệu gốc. Bản PostgreSQL vẫn được giữ nguyên riêng biệt.
============================================================================ */
/* ============================================================================
   TẠO VÀ CHỌN DATABASE ĐÍCH
   Có thể chạy toàn bộ file từ database master trong SSMS/sqlcmd.
============================================================================ */
IF DB_ID(N'CSDL_CHUNGCU&DANCU') IS NULL
BEGIN
    EXEC(N'CREATE DATABASE [CSDL_CHUNGCU&DANCU]');
END;
GO

USE [CSDL_CHUNGCU&DANCU];
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
GO


/* ================= MODULE: 01_schema_extensions_and_enums.sql ================= */
-- PostgreSQL ENUM declarations omitted; represented as VARCHAR(50) in tables.


/* ================= MODULE: 02_external_integrations_and_configs.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 02: External Integrations, Gateways & 3rd Party APIs
-- Quản lý tích hợp API: Cổng thanh toán, Hóa đơn điện tử, SMS/Zalo ZNS, IoT Hardware, Chữ ký số SmartCA, eKYC
-- ============================================================================

-- 1. CẤU HÌNH TÍCH HỢP BÊN NGOÀI (EXTERNAL INTEGRATION PROVIDERS)
CREATE TABLE external_providers (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    provider_code VARCHAR(60) NOT NULL UNIQUE, -- 'VNPAY', 'MOMO', 'VIETQR', 'VIETTEL_SINVOICE', 'ZALO_ZNS', 'FPT_EKYC', 'SMART_CA'
    provider_name VARCHAR(150) NOT NULL,
    service_group VARCHAR(50) NOT NULL, -- 'PAYMENT_GATEWAY', 'E_INVOICE', 'MESSAGING', 'EKYC', 'E_SIGNATURE', 'IOT_GATEWAY'
    is_active BIT NOT NULL DEFAULT 1,
    is_sandbox BIT NOT NULL DEFAULT 0,
    base_url VARCHAR(255) NOT NULL,
    auth_type VARCHAR(50) NOT NULL DEFAULT 'BEARER_TOKEN', -- 'API_KEY', 'BEARER_TOKEN', 'OAUTH2', 'HMAC_SHA256'
    auth_credentials NVARCHAR(MAX) NOT NULL DEFAULT N'{}', -- encrypted api_key, secret_key, merchant_id, cert_path
    webhook_secret VARCHAR(255),
    timeout_seconds INTEGER NOT NULL DEFAULT 30,
    retry_max_attempts INTEGER NOT NULL DEFAULT 3,
    metadata NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);


-- 2. NHẬT KÝ GỌI API NGOÀI (OUTBOUND API REQUEST AUDIT)
CREATE TABLE outbound_api_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    provider_code VARCHAR(60) NOT NULL,
    request_endpoint VARCHAR(500) NOT NULL,
    http_method VARCHAR(10) NOT NULL,
    request_headers NVARCHAR(MAX),
    request_payload NVARCHAR(MAX),
    response_status_code INTEGER,
    response_payload NVARCHAR(MAX),
    execution_time_ms INTEGER,
    is_success BIT NOT NULL DEFAULT 0,
    error_message TEXT,
    idempotency_key VARCHAR(100),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_outbound_api_provider ON outbound_api_logs (provider_code, created_at DESC);
CREATE INDEX idx_outbound_api_idempotency ON outbound_api_logs (idempotency_key);

-- 3. ĐĂNG KÝ VÀ NHẬN WEBHOOK TỪ BÊN NGOÀI (INBOUND WEBHOOK DISPATCHER)
CREATE TABLE inbound_webhooks (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    source_provider VARCHAR(60) NOT NULL, -- 'VNPAY', 'VIETQR', 'MOMO', 'VIETTEL_SINVOICE', 'IOT_CAMERA', 'SMART_LOCKER'
    event_type VARCHAR(100) NOT NULL, -- 'PAYMENT_SUCCESS', 'INVOICE_TAX_APPROVED', 'ANPR_CAMERA_DETECTED', 'LOCKER_OPENED'
    webhook_url VARCHAR(255) NOT NULL,
    request_headers NVARCHAR(MAX),
    payload NVARCHAR(MAX) NOT NULL,
    signature_header VARCHAR(255),
    is_signature_valid BIT NOT NULL DEFAULT 0,
    processing_status VARCHAR(30) NOT NULL DEFAULT 'PENDING', -- 'PENDING', 'PROCESSED', 'FAILED', 'IGNORED'
    processed_at DATETIME2(3),
    retry_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_inbound_webhooks_source ON inbound_webhooks (source_provider, processing_status, created_at DESC);

-- 4. THIẾT BỊ PHẦN CỨNG NGOẠI VI & IOT (HARDWARE & IOT DEVICES)
CREATE TABLE iot_devices (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    device_code VARCHAR(80) NOT NULL UNIQUE,
    device_name VARCHAR(150) NOT NULL,
    device_type VARCHAR(50) NOT NULL, -- 'ANPR_CAMERA', 'BARRIER_GATE', 'ELEVATOR_CARD_READER', 'SMART_WATER_METER', 'SMART_ELECTRIC_METER', 'SMART_PARCEL_LOCKER'
    ip_address VARCHAR(45),
    mac_address VARCHAR(30),
    firmware_version VARCHAR(50),
    communication_protocol VARCHAR(30) NOT NULL DEFAULT 'HTTP_REST', -- 'MQTT', 'MODBUS_TCP', 'HTTP_REST', 'RTSP', 'WIEGAND'
    mqtt_topic VARCHAR(200),
    rtsp_stream_url VARCHAR(255),
    location_description VARCHAR(255),
    is_online BIT NOT NULL DEFAULT 0,
    last_ping_at DATETIME2(3),
    config_params NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);



/* ================= MODULE: 03_rbac_and_identity.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 03: RBAC Phân Quyền, Định Danh & Tài Khoản (Identity & Access Management)
-- ============================================================================

-- 1. BẢNG VAI TRÒ HỆ THỐNG (ROLES)
CREATE TABLE roles (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    role_code VARCHAR(50) NOT NULL UNIQUE, -- 'SUPER_ADMIN', 'BUILDING_MANAGER', 'ACCOUNTANT', 'RECEPTIONIST', 'SECURITY_GUARD', 'TECHNICIAN', 'CLEANER', 'RESIDENT_OWNER', 'RESIDENT_MEMBER', 'DEVELOPER'
    role_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_system_role BIT NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

-- 2. BẢNG QUYỀN HẠN (PERMISSIONS)
CREATE TABLE permissions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    module VARCHAR(50) NOT NULL, -- 'INVOICE', 'TICKET', 'AMENITY', 'VISITOR', 'COMMUNITY', 'STAFF', 'AI_ENGINE', 'REPORT'
    permission_code VARCHAR(80) NOT NULL UNIQUE, -- 'INVOICE:CREATE', 'INVOICE:APPROVE', 'TICKET:TRIAGE_AI', 'COMMUNITY:MODERATE'
    permission_name VARCHAR(150) NOT NULL,
    description TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG GÁN QUYỀN CHO VAI TRÒ (ROLE_PERMISSIONS)
CREATE TABLE role_permissions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    role_id UNIQUEIDENTIFIER NOT NULL REFERENCES roles(id),
    permission_id UNIQUEIDENTIFIER NOT NULL REFERENCES permissions(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_role_permission UNIQUE (role_id, permission_id)
);

-- 4. BẢNG TÀI KHOẢN NGƯỜI DÙNG (USERS)
CREATE TABLE users (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    username VARCHAR(60) UNIQUE,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(120) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    avatar_url VARCHAR(500),
    gender VARCHAR(50) DEFAULT 'OTHER',
    date_of_birth DATE,
    national_id_number VARCHAR(25) UNIQUE, -- Số CCCD / Passport
    status VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
    mfa_enabled BIT NOT NULL DEFAULT 0,
    mfa_secret VARCHAR(120),
    last_login_at DATETIME2(3),
    last_login_ip VARCHAR(45),
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    lockout_until DATETIME2(3),
    fcm_device_token VARCHAR(500), -- Push notification token
    extra_preferences NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_users_phone ON users(phone_number);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_national_id ON users(national_id_number);

-- 5. BẢNG GÁN VAI TRÒ CHO NGƯỜI DÙNG (USER_ROLES)
CREATE TABLE user_roles (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    role_id UNIQUEIDENTIFIER NOT NULL REFERENCES roles(id),
    is_primary BIT NOT NULL DEFAULT 0,
    assigned_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by UNIQUEIDENTIFIER REFERENCES users(id),
    CONSTRAINT uq_user_role UNIQUE (user_id, role_id)
);

-- 6. PHIÊN ĐĂNG NHẬP (USER SESSIONS)
CREATE TABLE user_sessions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    refresh_token_hash VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(150),
    device_id VARCHAR(100),
    os_name VARCHAR(50),
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at DATETIME2(3) NOT NULL,
    is_revoked BIT NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sessions_user_id ON user_sessions(user_id, is_revoked);

-- 7. API KEYS DÀNH CHO DEVELOPER & HỆ THỐNG NGOÀI (API KEYS & SCOPES)
CREATE TABLE api_keys (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    name VARCHAR(120) NOT NULL,
    key_prefix VARCHAR(15) NOT NULL, -- e.g. 'apk_live_'
    hashed_secret VARCHAR(255) NOT NULL UNIQUE,
    allowed_scopes NVARCHAR(MAX) NOT NULL DEFAULT N'["read"]', -- e.g. ["invoices:read", "meters:write", "ai:inference"]
    ip_whitelist NVARCHAR(MAX) DEFAULT N'[]',
    rate_limit_per_minute INTEGER NOT NULL DEFAULT 60,
    is_active BIT NOT NULL DEFAULT 1,
    expires_at DATETIME2(3),
    last_used_at DATETIME2(3),
    created_by UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);


/* ================= MODULE: 04_buildings_and_apartments.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 04: Quản lý Khối Tòa Nhà (Blocks), Tầng (Floors) & Căn Hộ (Apartments)
-- ============================================================================

-- 1. BẢNG KHỐI TÒA NHÀ (BLOCKS / ZONES)
CREATE TABLE blocks (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    block_code VARCHAR(30) NOT NULL UNIQUE, -- 'BLOCK_A', 'BLOCK_B', 'TOWER_SAPPHIRE'
    block_name VARCHAR(120) NOT NULL, -- 'Tòa Nhà A - Ruby Tower'
    total_floors INTEGER NOT NULL DEFAULT 1,
    total_basements INTEGER NOT NULL DEFAULT 1,
    total_apartments INTEGER NOT NULL DEFAULT 0,
    address_line TEXT,
    hotline_phone VARCHAR(25),
    building_manager_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    ai_features_enabled BIT NOT NULL DEFAULT 1,
    metadata NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);


-- 2. BẢNG TẦNG (FLOORS)
CREATE TABLE floors (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    block_id UNIQUEIDENTIFIER NOT NULL REFERENCES blocks(id),
    floor_number INTEGER NOT NULL, -- 1, 2, 10, -1 (Hầm B1), -2 (Hầm B2)
    floor_code VARCHAR(30) NOT NULL, -- 'A-F02', 'B-B01'
    floor_name VARCHAR(80) NOT NULL, -- 'Tầng 02', 'Tầng Hầm B1'
    floor_type VARCHAR(50) NOT NULL DEFAULT 'RESIDENTIAL',
    total_units INTEGER NOT NULL DEFAULT 0,
    floor_plan_image_url VARCHAR(500),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL,
    CONSTRAINT uq_block_floor UNIQUE (block_id, floor_number)
);

-- 3. BẢNG CĂN HỘ (APARTMENTS)
CREATE TABLE apartments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    block_id UNIQUEIDENTIFIER NOT NULL REFERENCES blocks(id),
    floor_id UNIQUEIDENTIFIER NOT NULL REFERENCES floors(id),
    apartment_number VARCHAR(30) NOT NULL, -- 'A-1204', 'B-0802'
    room_type VARCHAR(50) DEFAULT '2_BEDROOM', -- 'STUDIO', '1_BEDROOM', '2_BEDROOM', '3_BEDROOM', 'PENTHOUSE', 'SHOHOUSE'
    gross_floor_area_sqm DECIMAL(8, 2) NOT NULL, -- Diện tích tim tường (m2)
    net_usable_area_sqm DECIMAL(8, 2) NOT NULL, -- Diện tích thông thủy (m2)
    bedroom_count INTEGER NOT NULL DEFAULT 2,
    bathroom_count INTEGER NOT NULL DEFAULT 2,
    water_quota_registered INTEGER NOT NULL DEFAULT 4, -- Định mức nhân khẩu nước sạch ưu đãi theo quy định
    status VARCHAR(50) NOT NULL DEFAULT 'VACANT',
    current_resident_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Cư dân đại diện hiện tại (chủ hộ hoặc khách thuê chính)
    monthly_management_fee_fixed DECIMAL(12, 2) DEFAULT 0, -- Phí quản lý căn hộ cố định nếu có
    has_balcony BIT DEFAULT 1,
    furnished_status VARCHAR(50) DEFAULT 'FULLY_FURNISHED',
    metadata NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL,
    CONSTRAINT uq_apartment_number UNIQUE (block_id, apartment_number)
);

CREATE INDEX idx_apartments_block_status ON apartments(block_id, status);
CREATE INDEX idx_apartments_current_resident ON apartments(current_resident_user_id);

-- 4. BẢNG LỊCH SỬ CHỦ SỞ HỮU CĂN HỘ (APARTMENT_OWNERS)
CREATE TABLE apartment_owners (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    owner_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    ownership_percentage DECIMAL(5, 2) NOT NULL DEFAULT 100.00,
    ownership_certificate_number VARCHAR(100), -- Số sổ hồng / Hợp đồng mua bán
    ownership_start_date DATE NOT NULL,
    ownership_end_date DATE,
    is_current_owner BIT NOT NULL DEFAULT 1,
    documents_json NVARCHAR(MAX) DEFAULT N'[]',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_apt_owner ON apartment_owners(apartment_id, is_current_owner);


/* ================= MODULE: 05_residents_and_occupants.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 05: Quản lý Cư Dân, Thành Viên, Tạm Trú, Thú Cưng & Chuyển Đồ
-- ============================================================================

-- 1. BẢNG CƯ DÂN (RESIDENTS)
CREATE TABLE residents (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    resident_type VARCHAR(50) NOT NULL DEFAULT 'OWNER',
    is_head_of_household BIT NOT NULL DEFAULT 0, -- Chủ hộ
    stay_start_date DATE NOT NULL,
    stay_end_date DATE,
    relationship_to_head VARCHAR(60) DEFAULT 'SELF', -- 'SELF', 'SPOUSE', 'CHILD', 'PARENT', 'TENANT', 'MAID'
    occupation VARCHAR(100),
    vehicle_count INTEGER NOT NULL DEFAULT 0,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL,
    CONSTRAINT uq_resident_apt UNIQUE (user_id, apartment_id)
);

CREATE INDEX idx_residents_apt ON residents(apartment_id, is_active);

-- 2. ĐĂNG KÝ TẠM TRÚ / TẠM VẮNG (TEMPORARY REGISTRATIONS)
CREATE TABLE temporary_registrations (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    resident_id UNIQUEIDENTIFIER NOT NULL REFERENCES residents(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    registration_type VARCHAR(30) NOT NULL, -- 'TEMPORARY_STAY' (Tạm trú), 'TEMPORARY_ABSENCE' (Tạm vắng)
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NOT NULL,
    police_status VARCHAR(50) DEFAULT 'PENDING_POLICE_SUBMISSION', -- 'PENDING', 'SUBMITTED_TO_POLICE', 'APPROVED', 'REJECTED'
    police_reference_code VARCHAR(100),
    identity_card_front_url VARCHAR(500),
    identity_card_back_url VARCHAR(500),
    reviewed_by UNIQUEIDENTIFIER REFERENCES users(id),
    reviewed_at DATETIME2(3),
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. LIÊN HỆ KHẨN CẤP CỦA CƯ DÂN (EMERGENCY CONTACTS)
CREATE TABLE emergency_contacts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    contact_name VARCHAR(150) NOT NULL,
    relationship VARCHAR(60) NOT NULL,
    phone_number VARCHAR(25) NOT NULL,
    is_primary BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. ĐĂNG KÝ THÚ CƯNG (PETS)
CREATE TABLE pets (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    owner_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    pet_name VARCHAR(80) NOT NULL,
    species VARCHAR(50) NOT NULL, -- 'DOG', 'CAT', 'BIRD', 'OTHER'
    breed VARCHAR(80),
    weight_kg DECIMAL(5, 2),
    photo_url VARCHAR(500),
    vaccination_record_url VARCHAR(500),
    is_rabies_vaccinated BIT NOT NULL DEFAULT 0,
    rabies_vaccine_expiry DATE,
    microchip_number VARCHAR(80),
    status VARCHAR(30) NOT NULL DEFAULT 'APPROVED', -- 'PENDING_APPROVAL', 'APPROVED', 'REVOKED'
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. LỊCH SỬ CHUYỂN ĐẾN / CHUYỂN ĐI & ĐẶT THANG MÁY CHUYỂN ĐỒ (MOVE IN / MOVE OUT)
CREATE TABLE move_records (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    applicant_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    move_type VARCHAR(20) NOT NULL, -- 'MOVE_IN', 'MOVE_OUT'
    planned_datetime DATETIME2(3) NOT NULL,
    cargo_elevator_reserved BIT NOT NULL DEFAULT 1,
    elevator_slot_start DATETIME2(3),
    elevator_slot_end DATETIME2(3),
    moving_truck_plate VARCHAR(30),
    driver_phone VARCHAR(25),
    estimated_item_count INTEGER,
    security_deposit_amount DECIMAL(12, 2) DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'PENDING_SECURITY_APPROVAL', -- 'PENDING', 'APPROVED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'
    security_guard_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    inspection_notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. NHẬT KÝ ĐỊNH DANH ĐIỆN TỬ (RESIDENT EKYC AUDIT)
CREATE TABLE resident_ekyc_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    provider_used VARCHAR(50) NOT NULL DEFAULT 'FPT_AI_EKYC',
    national_id_detected VARCHAR(30),
    full_name_detected VARCHAR(150),
    dob_detected DATE,
    face_match_percentage DECIMAL(5, 2),
    liveness_passed BIT NOT NULL DEFAULT 1,
    raw_ocr_response NVARCHAR(MAX),
    is_verified BIT NOT NULL DEFAULT 0,
    verified_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 06_contracts_and_signatures.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 06: Quản lý Hợp Đồng & Quy Trình Ký (Chữ Ký Điện Tử Online & Ký Trực Tiếp)
-- ============================================================================

-- 1. BẢNG HỢP ĐỒNG (CONTRACTS)
CREATE TABLE contracts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    contract_number VARCHAR(80) NOT NULL UNIQUE, -- 'HĐ-2026/09/A-1204-THUE'
    contract_title VARCHAR(200) NOT NULL,
    contract_type VARCHAR(50) NOT NULL DEFAULT 'LONG_TERM_LEASE',
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    primary_tenant_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    lessor_company_name VARCHAR(200) DEFAULT 'BAN QUẢN LÝ CHUNG CƯ SMART BUILDING',
    lessor_representative_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    payment_cycle_months INTEGER NOT NULL DEFAULT 1, -- Chu kỳ thanh toán (1 tháng, 3 tháng, 6 tháng)
    payment_due_day_of_month INTEGER NOT NULL DEFAULT 5, -- Ngày đến hạn hàng tháng
    
    signature_method VARCHAR(50) NOT NULL DEFAULT 'ONLINE_ESIGN',
    status VARCHAR(50) NOT NULL DEFAULT 'DRAFT',
    
    document_file_url VARCHAR(500), -- Bản PDF hợp đồng gốc
    document_hash_sha256 VARCHAR(64), -- SHA-256 checksum đảm bảo chống chỉnh sửa tài liệu
    signed_document_url VARCHAR(500), -- Bản PDF hoàn chỉnh sau khi hai bên ký xong
    
    terms_content_html TEXT,
    auto_renew BIT NOT NULL DEFAULT 0,
    termination_reason TEXT,
    terminated_at DATETIME2(3),
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_contracts_tenant ON contracts(primary_tenant_user_id, status);
CREATE INDEX idx_contracts_apt ON contracts(apartment_id, status);

-- 2. BẢNG CHI TIẾT CHỮ KÝ HỢP ĐỒNG (CONTRACT SIGNATURES - ONLINE & IN-PERSON)
CREATE TABLE contract_signatures (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    contract_id UNIQUEIDENTIFIER NOT NULL REFERENCES contracts(id),
    signer_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    signer_role VARCHAR(50) NOT NULL, -- 'LESSOR_REPRESENTATIVE', 'TENANT', 'GUARANTOR', 'WITNESS'
    
    signature_type VARCHAR(50) NOT NULL, -- 'ONLINE_DRAWN', 'ONLINE_OTP_VERIFIED', 'ONLINE_SMART_CA', 'IN_PERSON_WET_INK'
    signature_image_url VARCHAR(500), -- Ảnh nét chữ ký hoặc ảnh chụp bản scan chữ ký tươi
    
    -- DỮ LIỆU PHÁP LÝ CHỮ KÝ ONLINE (E-SIGNATURE AUDIT TRAIL)
    signer_ip_address VARCHAR(45),
    signer_user_agent TEXT,
    signed_timestamp DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    otp_code_hash VARCHAR(100),
    otp_verified_at DATETIME2(3),
    smart_ca_serial_number VARCHAR(120), -- Số serial chứng thư số (nếu ký qua VNPT/Viettel SmartCA)
    smart_ca_provider VARCHAR(50),
    digital_signature_cms_value TEXT, -- Chuỗi mã hóa Cryptographic Message Syntax
    
    -- DỮ LIỆU KÝ TRỰC TIẾP TẠI VĂN PHÒNG BQL (IN-PERSON PHYSICAL SIGNING)
    witness_staff_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Nhân viên BQL chứng kiến ký trực tiếp
    physical_paper_contract_code VARCHAR(100), -- Mã định danh bản lưu trữ tại kho hồ sơ
    in_person_signed_location VARCHAR(200) DEFAULT 'Văn phòng Ban Quản Lý Chung Cư',
    scanned_full_signed_pdf_url VARCHAR(500), -- File scan nguyên cuốn hợp đồng có mộc đỏ và chữ ký sống
    
    is_verified BIT NOT NULL DEFAULT 1,
    verification_notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_signatures_contract ON contract_signatures(contract_id);

-- 3. BẢNG TIỀN ĐẶT CỌC HỢP ĐỒNG (CONTRACT DEPOSITS)
CREATE TABLE contract_deposits (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    contract_id UNIQUEIDENTIFIER NOT NULL REFERENCES contracts(id),
    deposit_type VARCHAR(50) NOT NULL DEFAULT 'RENTAL_SECURITY_DEPOSIT', -- 'RENTAL_SECURITY_DEPOSIT', 'FIT_OUT_DEPOSIT' (cọc thi công)
    amount DECIMAL(12, 2) NOT NULL,
    received_date DATE NOT NULL,
    receiver_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    receipt_voucher_number VARCHAR(80),
    status VARCHAR(30) NOT NULL DEFAULT 'HELD', -- 'HELD', 'REFUNDED_FULL', 'PARTIALLY_DEDUCTED', 'FORFEITED'
    deducted_amount DECIMAL(12, 2) DEFAULT 0,
    deduction_reason TEXT,
    refunded_date DATE,
    refunded_amount DECIMAL(12, 2),
    refund_transaction_ref VARCHAR(100),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BẢNG PHỤ LỤC & ĐIỀU KHOẢN BỔ SUNG (CONTRACT CLAUSES & ADDENDUMS)
CREATE TABLE contract_addendums (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    contract_id UNIQUEIDENTIFIER NOT NULL REFERENCES contracts(id),
    addendum_number VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    effective_date DATE NOT NULL,
    summary_content TEXT NOT NULL,
    attached_pdf_url VARCHAR(500),
    created_by UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 07_meters_and_iot_readings.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 07: Cài Đặt Đơn Giá, Đồng Hồ & Chốt Chỉ Số Điện/Nước (Thủ Công, Excel & AI OCR)
-- ============================================================================

-- 1. BẢNG LOẠI DỊCH VỤ & CÀI ĐẶT ĐƠN GIÁ (SERVICE PRICING CONFIGS)
CREATE TABLE service_pricing_configs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    service_code VARCHAR(50) NOT NULL UNIQUE, -- 'ELECTRICITY_RESIDENTIAL', 'WATER_RESIDENTIAL', 'MANAGEMENT_FEE', 'PARKING_MOTORBIKE', 'PARKING_CAR'
    service_name VARCHAR(150) NOT NULL,
    meter_type VARCHAR(50), -- NULL nếu không dùng đồng hồ
    billing_type VARCHAR(50) NOT NULL DEFAULT 'TIERED_USAGE',
    unit_name VARCHAR(30) NOT NULL, -- 'kWh', 'm3', 'tháng', 'm2/tháng', 'lượt'
    fixed_unit_price DECIMAL(12, 2) DEFAULT 0, -- Dùng khi billing_type = 'FIXED_MONTHLY' hoặc 'UNIT_PRICE_USAGE'
    vat_percentage DECIMAL(5, 2) NOT NULL DEFAULT 10.00,
    environmental_protection_fee_pct DECIMAL(5, 2) DEFAULT 0.00, -- Phí bảo vệ môi trường (nước thải) e.g. 10%
    effective_from_date DATE NOT NULL,
    effective_to_date DATE,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

-- 2. BẢNG BẬC THANG GIÁ ĐIỆN / NƯỚC (PRICING TIERS)
CREATE TABLE pricing_tiers (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    pricing_config_id UNIQUEIDENTIFIER NOT NULL REFERENCES service_pricing_configs(id),
    tier_order INTEGER NOT NULL, -- 1, 2, 3, 4, 5, 6
    tier_name VARCHAR(80) NOT NULL, -- 'Bậc 1 (0 - 50 kWh)', 'Bậc 2 (51 - 100 kWh)'
    min_usage_threshold DECIMAL(10, 2) NOT NULL, -- e.g. 0
    max_usage_threshold DECIMAL(10, 2), -- e.g. 50 (NULL nếu là bậc cao nhất không giới hạn)
    unit_price DECIMAL(12, 2) NOT NULL, -- Đơn giá chưa thuế
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_config_tier_order UNIQUE (pricing_config_id, tier_order)
);

-- 3. BẢNG THIẾT BỊ ĐỒNG HỒ ĐO (METERS)
CREATE TABLE meters (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    meter_code VARCHAR(60) NOT NULL UNIQUE, -- 'WM-A-1204', 'EM-B-0802'
    meter_type VARCHAR(50) NOT NULL,
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    iot_device_id UNIQUEIDENTIFIER REFERENCES iot_devices(id), -- Nếu là đồng hồ thông minh kết nối mạng
    installation_date DATE NOT NULL,
    initial_reading DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    current_reading DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    last_reading_date DATE,
    multiplier_factor DECIMAL(6, 2) NOT NULL DEFAULT 1.00, -- Hệ số nhân
    calibration_due_date DATE, -- Hạn kiểm định định kỳ
    is_active BIT NOT NULL DEFAULT 1,
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_meters_apt ON meters(apartment_id, meter_type);

-- 4. BẢNG GÓI IMPORT CHỈ SỐ HÀNG LOẠT TỪ EXCEL (METER READING IMPORT BATCHES)
CREATE TABLE meter_reading_batches (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    batch_code VARCHAR(60) NOT NULL UNIQUE, -- 'BATCH-202609-WATER-BLOCK_A'
    billing_month_year VARCHAR(7) NOT NULL, -- '2026-09'
    meter_type VARCHAR(50) NOT NULL,
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    file_name VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    uploaded_by UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    total_records INTEGER NOT NULL DEFAULT 0,
    success_records INTEGER NOT NULL DEFAULT 0,
    failed_records INTEGER NOT NULL DEFAULT 0,
    import_status VARCHAR(30) NOT NULL DEFAULT 'PROCESSING', -- 'PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'
    error_summary_json NVARCHAR(MAX) DEFAULT N'[]',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME2(3)
);

-- 5. BẢNG CHỐT CHỈ SỐ ĐO (METER READINGS)
CREATE TABLE meter_readings (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    meter_id UNIQUEIDENTIFIER NOT NULL REFERENCES meters(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    batch_id UNIQUEIDENTIFIER REFERENCES meter_reading_batches(id),
    billing_cycle VARCHAR(7) NOT NULL, -- '2026-09'
    period_start_date DATE NOT NULL,
    period_end_date DATE NOT NULL,
    
    previous_reading DECIMAL(10, 2) NOT NULL,
    current_reading DECIMAL(10, 2) NOT NULL,
    consumed_units DECIMAL(10, 2) NOT NULL, -- current_reading - previous_reading
    
    reading_source VARCHAR(50) NOT NULL DEFAULT 'MANUAL',
    recorded_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    
    -- DỮ LIỆU ẢNH CHỤP ĐỒNG HỒ & AI OCR
    meter_photo_url VARCHAR(500),
    ai_detected_reading DECIMAL(10, 2),
    ai_confidence_score DECIMAL(5, 2), -- e.g. 98.50%
    is_abnormal_consumption BIT NOT NULL DEFAULT 0, -- AI phát hiện tăng vọt bất thường
    abnormal_reason VARCHAR(255),
    
    is_locked_for_billing BIT NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_meter_cycle UNIQUE (meter_id, billing_cycle)
);

CREATE INDEX idx_readings_cycle ON meter_readings(billing_cycle, apartment_id);
CREATE INDEX idx_readings_abnormal ON meter_readings(is_abnormal_consumption) WHERE is_abnormal_consumption = 1;


/* ================= MODULE: 08_invoices_and_e_invoicing.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 08: Sinh Hóa Đơn Tự Động (Batch Processing) & Hóa Đơn Điện Tử (E-Invoice)
-- ============================================================================

-- 1. BẢNG TIẾN TRÌNH SINH HÓA ĐƠN HÀNG LOẠT (INVOICE GENERATION BATCH RUNS)
CREATE TABLE invoice_generation_batches (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    batch_number VARCHAR(60) NOT NULL UNIQUE, -- 'INV-BATCH-202609-ALL'
    billing_period VARCHAR(7) NOT NULL, -- '2026-09'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu chạy toàn bộ các tòa
    executed_by_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    total_apartments_processed INTEGER NOT NULL DEFAULT 0,
    total_invoices_created INTEGER NOT NULL DEFAULT 0,
    total_amount_calculated DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'RUNNING', -- 'QUEUED', 'RUNNING', 'COMPLETED', 'FAILED'
    error_logs NVARCHAR(MAX) DEFAULT N'[]',
    started_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME2(3)
);

-- 2. BẢNG HÓA ĐƠN TỔNG HỢP (INVOICES)
CREATE TABLE invoices (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    invoice_number VARCHAR(80) NOT NULL UNIQUE, -- 'HD-202609-A-1204'
    batch_id UNIQUEIDENTIFIER REFERENCES invoice_generation_batches(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    resident_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    billing_period VARCHAR(7) NOT NULL, -- '2026-09'
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    
    subtotal_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    previous_debt_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00, -- Công nợ tồn từ tháng trước
    total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00, -- Tổng phải trả
    paid_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    remaining_balance DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    
    status VARCHAR(50) NOT NULL DEFAULT 'ISSUED',
    notes TEXT,
    
    -- TÍCH HỢP HÓA ĐƠN ĐIỆN TỬ BÊN NGOÀI (VIETTEL SINVOICE / VNPT / MISA)
    e_invoice_provider VARCHAR(50),
    e_invoice_template_code VARCHAR(50), -- Mẫu số: 1/001
    e_invoice_series VARCHAR(20), -- Ký hiệu: C26TMB
    e_invoice_number VARCHAR(50), -- Số HĐĐT do thuế cấp
    e_invoice_tax_auth_code VARCHAR(100), -- Mã xác thực của Tổng cục Thuế
    e_invoice_lookup_url VARCHAR(500), -- Link cư dân tra cứu hóa đơn điện tử
    e_invoice_synced_at DATETIME2(3),
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL,
    CONSTRAINT uq_invoice_apt_period UNIQUE (apartment_id, billing_period)
);

CREATE INDEX idx_invoices_period_status ON invoices(billing_period, status);
CREATE INDEX idx_invoices_resident ON invoices(resident_user_id, status);
CREATE INDEX idx_invoices_apt ON invoices(apartment_id, status);

-- 3. BẢNG CHI TIẾT MỤC TRONG HÓA ĐƠN (INVOICE ITEMS)
CREATE TABLE invoice_items (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    invoice_id UNIQUEIDENTIFIER NOT NULL REFERENCES invoices(id),
    service_code VARCHAR(50) NOT NULL, -- 'ELECTRICITY', 'WATER', 'MANAGEMENT_FEE', 'PARKING_CAR', 'BBQ_BOOKING'
    item_description VARCHAR(255) NOT NULL,
    
    meter_reading_id UNIQUEIDENTIFIER REFERENCES meter_readings(id),
    previous_reading DECIMAL(10, 2),
    current_reading DECIMAL(10, 2),
    
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    unit_name VARCHAR(30) NOT NULL, -- 'kWh', 'm3', 'tháng', 'lượt'
    unit_price DECIMAL(12, 2) NOT NULL,
    
    amount_before_tax DECIMAL(14, 2) NOT NULL,
    vat_percentage DECIMAL(5, 2) NOT NULL DEFAULT 10.00,
    vat_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    environmental_fee_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    total_line_amount DECIMAL(14, 2) NOT NULL,
    
    tier_calculation_details NVARCHAR(MAX) DEFAULT N'[]', -- Chi tiết tiền tính theo từng bậc nếu là điện/nước
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_invoice_items_inv ON invoice_items(invoice_id);

-- 4. BẢNG PHIẾU ĐIỀU CHỈNH / GIẢM TRỪ HÓA ĐƠN (CREDIT NOTES / ADJUSTMENTS)
CREATE TABLE invoice_adjustments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    invoice_id UNIQUEIDENTIFIER NOT NULL REFERENCES invoices(id),
    adjustment_type VARCHAR(30) NOT NULL, -- 'DISCOUNT', 'OVERCHARGE_REFUND', 'WAIVED_PENALTY'
    adjustment_amount DECIMAL(14, 2) NOT NULL,
    reason TEXT NOT NULL,
    approved_by_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 09_payments_and_transactions.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 09: Thanh Toán (VietQR, VNPay, MoMo), Biên Lai & Hàng Đợi Nhắc Nợ
-- ============================================================================

-- 1. BẢNG GIAO DỊCH THANH TOÁN (PAYMENTS)
CREATE TABLE payments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    payment_reference_code VARCHAR(80) NOT NULL UNIQUE, -- 'PAY-202609-A1204-987654'
    invoice_id UNIQUEIDENTIFIER NOT NULL REFERENCES invoices(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    payer_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    amount_paid DECIMAL(14, 2) NOT NULL,
    payment_gateway VARCHAR(50) NOT NULL DEFAULT 'VIETQR_BANK_TRANSFER',
    gateway_transaction_id VARCHAR(120), -- Mã giao dịch ngân hàng / VNPay / MoMo
    idempotency_key VARCHAR(100) UNIQUE, -- Khóa chống thanh toán 2 lần
    
    payment_status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
    payment_time DATETIME2(3),
    
    -- DỮ LIỆU VIETQR & CỔNG THANH TOÁN
    qr_code_content TEXT, -- Chuỗi chuẩn VietQR payload hoặc link ảnh QR
    bank_account_number VARCHAR(50),
    bank_bin VARCHAR(20),
    gateway_callback_payload NVARCHAR(MAX),
    failure_reason TEXT,
    
    recorded_by_staff_id UNIQUEIDENTIFIER REFERENCES users(id), -- NULL nếu thanh toán online tự động
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_payments_invoice ON payments(invoice_id, payment_status);
CREATE INDEX idx_payments_apartment ON payments(apartment_id, payment_time DESC);
CREATE INDEX idx_payments_payer ON payments(payer_user_id, payment_time DESC);

-- 2. BẢNG BIÊN LAI THU TIỀN ĐIỆN TỬ (PAYMENT RECEIPTS)
CREATE TABLE payment_receipts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    receipt_number VARCHAR(80) NOT NULL UNIQUE, -- 'BL-202609-00123'
    payment_id UNIQUEIDENTIFIER NOT NULL REFERENCES payments(id),
    receipt_date DATE NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    amount_in_words VARCHAR(255) NOT NULL,
    received_from_name VARCHAR(150) NOT NULL,
    pdf_receipt_url VARCHAR(500),
    digital_signature_hash VARCHAR(100),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG HÀNG ĐỢI GỬI THÔNG BÁO NHẮC NỢ (DEBT REMINDER QUEUE)
CREATE TABLE debt_reminder_queue (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    invoice_id UNIQUEIDENTIFIER NOT NULL REFERENCES invoices(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    recipient_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    reminder_level INTEGER NOT NULL DEFAULT 1, -- 1: Trước hạn 3 ngày, 2: Đúng hạn, 3: Quá hạn 5 ngày, 4: Cảnh báo cắt dịch vụ
    channel VARCHAR(50) NOT NULL DEFAULT 'EMAIL',
    
    scheduled_send_time DATETIME2(3) NOT NULL,
    sent_at DATETIME2(3),
    status VARCHAR(30) NOT NULL DEFAULT 'QUEUED', -- 'QUEUED', 'SENDING', 'SENT', 'FAILED', 'CANCELLED'
    
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    error_log TEXT,
    
    email_subject VARCHAR(255),
    message_content TEXT NOT NULL,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_debt_reminder_status ON debt_reminder_queue(status, scheduled_send_time);


/* ================= MODULE: 10_amenities_and_smart_booking.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 10: Tiện Ích Tòa Nhà & Đăng Ký Sử Dụng (Smart Booking & Validation)
-- ============================================================================

-- 1. BẢNG DANH MỤC TIỆN ÍCH (AMENITY CATEGORIES)
CREATE TABLE amenity_categories (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    category_name VARCHAR(100) NOT NULL UNIQUE, -- 'Hồ bơi', 'Khu BBQ', 'Phòng Gym', 'Sân Tennis', 'Phòng Sinh Hoạt Cộng Đồng'
    category_code VARCHAR(50) NOT NULL UNIQUE,
    icon_name VARCHAR(50),
    description TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG TIỆN ÍCH CỤ THỂ (AMENITIES)
CREATE TABLE amenities (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    category_id UNIQUEIDENTIFIER NOT NULL REFERENCES amenity_categories(id),
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu dùng chung cho toàn bộ dự án
    amenity_name VARCHAR(150) NOT NULL, -- 'Vườn nướng BBQ Sky Garden Tòa A'
    amenity_code VARCHAR(60) NOT NULL UNIQUE,
    location_detail VARCHAR(255) NOT NULL, -- 'Tầng thượng Tòa A'
    
    max_capacity_per_slot INTEGER NOT NULL DEFAULT 10,
    hourly_rate DECIMAL(12, 2) NOT NULL DEFAULT 0.00, -- Miễn phí hoặc có phí
    security_deposit_required DECIMAL(12, 2) DEFAULT 0.00,
    
    advance_booking_days_limit INTEGER NOT NULL DEFAULT 7, -- Cho phép đặt trước tối đa bao nhiêu ngày
    min_cancel_hours_before INTEGER NOT NULL DEFAULT 12, -- Hủy trước ít nhất bao nhiêu giờ
    requires_admin_approval BIT NOT NULL DEFAULT 0,
    
    rules_and_regulations TEXT,
    cover_image_url VARCHAR(500),
    gallery_images NVARCHAR(MAX) DEFAULT N'[]',
    is_active BIT NOT NULL DEFAULT 1,
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

-- 3. BẢNG KHUNG GIỜ KHẢ DỤNG (AMENITY TIME SLOTS)
CREATE TABLE amenity_time_slots (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    amenity_id UNIQUEIDENTIFIER NOT NULL REFERENCES amenities(id),
    day_of_week INTEGER NOT NULL, -- 0: Chủ nhật, 1-6: Thứ 2 đến Thứ 7
    slot_start_time TIME NOT NULL, -- 09:00:00
    slot_end_time TIME NOT NULL, -- 11:30:00
    slot_label VARCHAR(60), -- 'Khung giờ trưa 1'
    max_bookings INTEGER NOT NULL DEFAULT 1, -- Số lượt đặt cùng lúc cho slot này (ví dụ bếp BBQ: 1 slot = 1 hộ)
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_amenity_day_slot UNIQUE (amenity_id, day_of_week, slot_start_time, slot_end_time)
);

-- 4. BẢNG NGÀY ĐÓNG CỬA BẢO TRÌ TIỆN ÍCH (AMENITY BLACKOUT DATES)
CREATE TABLE amenity_blackouts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    amenity_id UNIQUEIDENTIFIER NOT NULL REFERENCES amenities(id),
    blackout_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    reason VARCHAR(255) NOT NULL, -- 'Bảo dưỡng định kỳ', 'Nghỉ Tết Nguyên Đán'
    created_by UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG ĐĂNG KÝ SỬ DỤNG TIỆN ÍCH (AMENITY BOOKINGS)
CREATE TABLE amenity_bookings (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    booking_code VARCHAR(60) NOT NULL UNIQUE, -- 'BKG-20260909-BBQ-001'
    amenity_id UNIQUEIDENTIFIER NOT NULL REFERENCES amenities(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    resident_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    attendee_count INTEGER NOT NULL DEFAULT 1,
    
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    deposit_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    is_paid BIT NOT NULL DEFAULT 1,
    payment_id UNIQUEIDENTIFIER REFERENCES payments(id),
    
    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
    approved_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    approved_at DATETIME2(3),
    rejection_reason TEXT,
    
    -- CHECK-IN THỰC TẾ
    checkin_qr_code VARCHAR(120) NOT NULL UNIQUE,
    checked_in_at DATETIME2(3),
    checked_in_by_staff_id UNIQUEIDENTIFIER REFERENCES users(id),
    
    resident_notes TEXT,
    admin_notes TEXT,
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_bookings_conflict_lookup ON amenity_bookings(amenity_id, booking_date, status);
CREATE INDEX idx_bookings_resident ON amenity_bookings(resident_user_id, booking_date DESC);


/* ================= MODULE: 11_tickets_and_field_service.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 11: Yêu Cầu / Báo Cáo Sự Cố (Tickets), Điều Phối Kỹ Thuật & SLA
-- ============================================================================

-- 1. BẢNG DANH MỤC SỰ CỐ / YÊU CẦU (TICKET CATEGORIES)
CREATE TABLE ticket_categories (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    category_code VARCHAR(50) NOT NULL UNIQUE, -- 'ELECTRICAL', 'PLUMBING', 'ELEVATOR', 'NOISE_COMPLAINT', 'CLEANING', 'SECURITY'
    category_name VARCHAR(120) NOT NULL,
    default_priority VARCHAR(50) NOT NULL DEFAULT 'MEDIUM',
    sla_response_time_minutes INTEGER NOT NULL DEFAULT 60, -- Thời hạn phản hồi tối đa
    sla_resolution_time_hours INTEGER NOT NULL DEFAULT 24, -- Thời hạn hoàn thành tối đa
    default_assigned_team VARCHAR(50) DEFAULT 'TECHNICAL_DEPARTMENT',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG YÊU CẦU / BÁO CÁO SỰ CỐ (TICKETS)
CREATE TABLE tickets (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_number VARCHAR(60) NOT NULL UNIQUE, -- 'TCK-202609-00128'
    category_id UNIQUEIDENTIFIER NOT NULL REFERENCES ticket_categories(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    creator_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    priority VARCHAR(50) NOT NULL DEFAULT 'MEDIUM',
    status VARCHAR(50) NOT NULL DEFAULT 'NEW',
    
    -- ĐIỀU PHỐI KỸ THUẬT VIÊN
    current_technician_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    coordinator_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Người phân công
    
    preferred_service_time DATETIME2(3), -- Giờ cư dân muốn nhân viên đến sửa
    sla_deadline DATETIME2(3),
    resolved_at DATETIME2(3),
    closed_at DATETIME2(3),
    
    -- TÍNH PHÍ SỬA CHỮA (NẾU NGOÀI PHẠM VI BẢO HÀNH MIỄN PHÍ)
    is_billable BIT NOT NULL DEFAULT 0,
    estimated_repair_cost DECIMAL(12, 2) DEFAULT 0.00,
    actual_repair_cost DECIMAL(12, 2) DEFAULT 0.00,
    invoice_id UNIQUEIDENTIFIER REFERENCES invoices(id),
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_tickets_status ON tickets(status, priority);
CREATE INDEX idx_tickets_apt ON tickets(apartment_id, created_at DESC);
CREATE INDEX idx_tickets_tech ON tickets(current_technician_user_id, status);

-- 3. BẢNG TỆP TIN & HÌNH ẢNH ĐÍNH KÈM SỰ CỐ (TICKET ATTACHMENTS)
CREATE TABLE ticket_attachments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_id UNIQUEIDENTIFIER NOT NULL REFERENCES tickets(id),
    file_name VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL, -- 'IMAGE', 'VIDEO', 'AUDIO', 'DOCUMENT'
    file_size_bytes BIGINT,
    is_resolution_proof BIT NOT NULL DEFAULT 0, -- Ảnh chụp nghiệm thu sau khi sửa xong
    uploaded_by_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BẢNG BÌNH LUẬN & TRAO ĐỔI TRÊN TICKET (TICKET COMMENTS)
CREATE TABLE ticket_comments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_id UNIQUEIDENTIFIER NOT NULL REFERENCES tickets(id),
    sender_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    comment_text TEXT NOT NULL,
    is_internal_staff_note BIT NOT NULL DEFAULT 0, -- Ghi chú nội bộ giữa kỹ thuật và admin, cư dân không thấy
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG LỊCH SỬ THAY ĐỔI TRẠNG THÁI / WORKFLOW LOG (TICKET HISTORY)
CREATE TABLE ticket_history (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_id UNIQUEIDENTIFIER NOT NULL REFERENCES tickets(id),
    action_type VARCHAR(50) NOT NULL, -- 'CREATED', 'AI_TRIAGED', 'ASSIGNED', 'STATUS_CHANGED', 'RESOLVED'
    old_status VARCHAR(50),
    new_status VARCHAR(50),
    performed_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. BẢNG ĐÁNH GIÁ CHẤT LƯỢNG SỬA CHỮA CỦA CƯ DÂN (TICKET RATINGS)
CREATE TABLE ticket_ratings (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_id UNIQUEIDENTIFIER NOT NULL UNIQUE REFERENCES tickets(id),
    resident_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    rating_stars INTEGER NOT NULL CHECK (rating_stars BETWEEN 1 AND 5),
    feedback_tags NVARCHAR(MAX) DEFAULT N'[]', -- ['Đúng giờ', 'Thân thiện', 'Kỹ thuật tốt']
    review_comment TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 12_announcements_and_notifications.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 12: Bảng Tin Thông Báo (Announcements) & Trung Tâm Thông Báo Đa Kênh
-- ============================================================================

-- 1. BẢNG THÔNG BÁO / BẢNG TIN (ANNOUNCEMENTS)
CREATE TABLE announcements (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    title VARCHAR(255) NOT NULL,
    summary_excerpt VARCHAR(500),
    content_html TEXT NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'GENERAL', -- 'EMERGENCY', 'MAINTENANCE', 'FEES', 'COMMUNITY_EVENT', 'GENERAL'
    
    -- PHẠM VI GỬI THÔNG BÁO (SCOPE)
    target_scope VARCHAR(50) NOT NULL DEFAULT 'ALL_BLOCKS',
    target_block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- Nếu thông báo riêng cho Tòa A hoặc Tòa B
    target_floor_id UNIQUEIDENTIFIER REFERENCES floors(id), -- Nếu thông báo riêng cho tầng cụ thể
    
    is_pinned BIT NOT NULL DEFAULT 0,
    is_urgent BIT NOT NULL DEFAULT 0,
    requires_acknowledgement BIT NOT NULL DEFAULT 0, -- Cư dân phải bấm 'Đã đọc & Đồng ý'
    
    publish_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME2(3),
    
    author_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_announcements_scope ON announcements(target_scope, target_block_id, publish_at DESC);

-- 2. BẢNG TỆP ĐÍNH KÈM THÔNG BÁO (ANNOUNCEMENT ATTACHMENTS)
CREATE TABLE announcement_attachments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    announcement_id UNIQUEIDENTIFIER NOT NULL REFERENCES announcements(id),
    file_name VARCHAR(255) NOT NULL,
    file_url VARCHAR(500) NOT NULL,
    file_size_bytes BIGINT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG THEO DÕI TRẠNG THÁI ĐÃ ĐỌC THÔNG BÁO (ANNOUNCEMENT READ RECEIPTS)
CREATE TABLE announcement_reads (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    announcement_id UNIQUEIDENTIFIER NOT NULL REFERENCES announcements(id),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    read_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME2(3),
    CONSTRAINT uq_announcement_user_read UNIQUE (announcement_id, user_id)
);

-- 4. BẢNG MẪU THÔNG BÁO ĐA KÊNH (NOTIFICATION TEMPLATES)
CREATE TABLE notification_templates (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    template_code VARCHAR(80) NOT NULL UNIQUE, -- 'TPL_INVOICE_ISSUED', 'TPL_PARCEL_ARRIVED', 'TPL_SOS_ALERT'
    template_name VARCHAR(150) NOT NULL,
    channel VARCHAR(50) NOT NULL,
    title_template VARCHAR(255),
    body_template TEXT NOT NULL,
    parameters_schema NVARCHAR(MAX) DEFAULT N'[]', -- ['resident_name', 'apartment_number', 'amount']
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG HỘP THƯ THÔNG BÁO NỘI BỘ TRONG APP CƯ DÂN (USER IN-APP NOTIFICATIONS)
CREATE TABLE user_in_app_notifications (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    recipient_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    title VARCHAR(255) NOT NULL,
    body_message TEXT NOT NULL,
    deep_link_url VARCHAR(255), -- 'app://invoices/HD-202609-001'
    category VARCHAR(50) DEFAULT 'SYSTEM',
    is_read BIT NOT NULL DEFAULT 0,
    read_at DATETIME2(3),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_in_app_notifs ON user_in_app_notifications(recipient_user_id, is_read, created_at DESC);


/* ================= MODULE: 13_community_block_scoped.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 13: Không Gian Cộng Đồng Dân Cư Phân Tách Theo Từng Tòa Nhà (Block-Scoped Community)
-- "Cư dân thuộc tòa nhà A thì có cộng đồng A, thuộc tòa B có cộng đồng B"
-- ============================================================================

-- 1. BẢNG KHÔNG GIAN CỘNG ĐỒNG THEO TÒA (COMMUNITY SPACES)
CREATE TABLE community_spaces (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    space_name VARCHAR(150) NOT NULL, -- 'Cộng Đồng Cư Dân Tòa Nhà A', 'Cộng Đồng Tòa Nhà B', 'Diễn Đàn Chung Toàn Khu'
    space_code VARCHAR(60) NOT NULL UNIQUE, -- 'COMMUNITY_BLOCK_A', 'COMMUNITY_BLOCK_B', 'COMMUNITY_ALL'
    scope_type VARCHAR(50) NOT NULL DEFAULT 'BLOCK_SPECIFIC',
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu là cộng đồng chung toàn khu
    description TEXT,
    rules_text TEXT,
    avatar_url VARCHAR(500),
    cover_banner_url VARCHAR(500),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG THÀNH VIÊN KHÔNG GIAN CỘNG ĐỒNG (COMMUNITY MEMBERSHIPS)
CREATE TABLE community_memberships (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    community_space_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_spaces(id),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    member_role VARCHAR(50) NOT NULL DEFAULT 'MEMBER', -- 'MEMBER', 'BLOCK_REPRESENTATIVE', 'MODERATOR', 'COMMUNITY_ADMIN'
    is_muted BIT NOT NULL DEFAULT 0, -- Bị khóa mõm tạm thời do vi phạm tiêu chuẩn cộng đồng
    muted_until DATETIME2(3),
    joined_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_community_space UNIQUE (community_space_id, user_id)
);

CREATE INDEX idx_comm_membership ON community_memberships(user_id, community_space_id);

-- 3. BẢNG CHUYÊN MỤC THẢO LUẬN TRONG TÒA (FORUM TOPIC CATEGORIES)
CREATE TABLE forum_categories (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    community_space_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_spaces(id),
    category_name VARCHAR(100) NOT NULL, -- 'Chợ Trao Đổi Cư Dân Tòa A', 'Góp Ý Ban Đại Diện Tòa A', 'Hỏi Đáp Kinh Nghiệm'
    category_slug VARCHAR(120) NOT NULL,
    icon_name VARCHAR(50),
    display_order INTEGER NOT NULL DEFAULT 1,
    is_admin_only_post BIT NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_comm_slug UNIQUE (community_space_id, category_slug)
);

-- 4. BẢNG BÀI VIẾT DIỄN ĐÀN CỘNG ĐỒNG (FORUM POSTS)
CREATE TABLE forum_posts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    community_space_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_spaces(id),
    category_id UNIQUEIDENTIFIER NOT NULL REFERENCES forum_categories(id),
    author_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    title VARCHAR(255) NOT NULL,
    content_text TEXT NOT NULL,
    attached_images NVARCHAR(MAX) DEFAULT N'[]', -- Danh sách link ảnh đính kèm bài viết
    
    is_anonymous BIT NOT NULL DEFAULT 0, -- Ẩn danh tác giả đối với cư dân khác
    is_pinned BIT NOT NULL DEFAULT 0,
    is_locked BIT NOT NULL DEFAULT 0, -- Khóa bình luận
    
    view_count INTEGER NOT NULL DEFAULT 0,
    like_count INTEGER NOT NULL DEFAULT 0,
    comment_count INTEGER NOT NULL DEFAULT 0,
    
    -- KIỂM DUYỆT BỞI AI (AI CONTENT MODERATION)
    ai_moderation_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED', -- 'APPROVED', 'FLAGGED_TOXIC', 'PENDING_ADMIN_REVIEW'
    ai_toxicity_score DECIMAL(5, 2) DEFAULT 0.00,
    ai_moderation_reason TEXT,
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_posts_comm_space ON forum_posts(community_space_id, created_at DESC);
CREATE INDEX idx_posts_author ON forum_posts(author_user_id);

-- 5. BẢNG BÌNH LUẬN BÀI VIẾT (FORUM COMMENTS)
CREATE TABLE forum_comments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    post_id UNIQUEIDENTIFIER NOT NULL REFERENCES forum_posts(id),
    parent_comment_id UNIQUEIDENTIFIER REFERENCES forum_comments(id), -- Hỗ trợ trả lời theo luồng (Threaded comments)
    author_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    comment_text TEXT NOT NULL,
    attached_image_url VARCHAR(500),
    is_anonymous BIT NOT NULL DEFAULT 0,
    like_count INTEGER NOT NULL DEFAULT 0,
    
    ai_moderation_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
    ai_toxicity_score DECIMAL(5, 2) DEFAULT 0.00,
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_comments_post ON forum_comments(post_id, created_at ASC);

-- 6. BẢNG THÍCH / TƯƠNG TÁC BÀI VIẾT & BÌNH LUẬN (FORUM REACTIONS)
CREATE TABLE forum_reactions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    target_type VARCHAR(20) NOT NULL, -- 'POST', 'COMMENT'
    target_id UNIQUEIDENTIFIER NOT NULL,
    reaction_type VARCHAR(30) NOT NULL DEFAULT 'LIKE', -- 'LIKE', 'HEART', 'AGREE', 'DISAGREE'
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_reaction UNIQUE (user_id, target_type, target_id)
);

-- 7. BẢNG BIỂU QUYẾT / THĂM DÒ Ý KIẾN RIÊNG TỪNG TÒA (COMMUNITY POLLS & VOTING)
CREATE TABLE community_polls (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    community_space_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_spaces(id),
    creator_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    poll_title VARCHAR(255) NOT NULL, -- 'Biểu quyết: Sơn lại hành lang Tòa Nhà A năm 2026'
    description TEXT,
    voting_scope VARCHAR(30) NOT NULL DEFAULT 'ONE_VOTE_PER_APARTMENT', -- 'ONE_VOTE_PER_APARTMENT', 'ONE_VOTE_PER_RESIDENT'
    start_time DATETIME2(3) NOT NULL,
    end_time DATETIME2(3) NOT NULL,
    is_closed BIT NOT NULL DEFAULT 0,
    total_votes INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 8. CÁC LỰA CHỌN TRONG BIỂU QUYẾT (POLL OPTIONS)
CREATE TABLE community_poll_options (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    poll_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_polls(id),
    option_text VARCHAR(255) NOT NULL, -- 'Đồng ý', 'Không đồng ý', 'Ý kiến khác'
    display_order INTEGER NOT NULL DEFAULT 1,
    vote_count INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 9. PHIẾU BẦU CỦA CƯ DÂN (POLL VOTES)
CREATE TABLE community_poll_votes (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    poll_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_polls(id),
    option_id UNIQUEIDENTIFIER NOT NULL REFERENCES community_poll_options(id),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    voted_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_poll_apartment_vote UNIQUE (poll_id, apartment_id) -- Mỗi căn hộ biểu quyết 1 phiếu duy nhất
);


/* ================= MODULE: 14_feedback_and_surveys.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 14: Hòm Thư Góp Ý & Khảo Sát Mức Độ Hài Lòng Cư Dân (CSAT Surveys)
-- ============================================================================

-- 1. BẢNG HÒM THƯ GÓP Ý CƯ DÂN (SUGGESTION BOXES)
CREATE TABLE suggestion_boxes (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    box_name VARCHAR(150) NOT NULL, -- 'Hòm thư đóng góp xây dựng Chung Cư Văn Minh'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu áp dụng toàn khu
    description TEXT,
    is_open BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG Ý KIẾN ĐÓNG GÓP / GÓP Ý (SUGGESTIONS)
CREATE TABLE suggestions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    box_id UNIQUEIDENTIFIER NOT NULL REFERENCES suggestion_boxes(id),
    resident_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- NULL nếu cư dân chọn gửi hoàn toàn ẩn danh
    apartment_id UNIQUEIDENTIFIER REFERENCES apartments(id),
    is_anonymous BIT NOT NULL DEFAULT 0,
    
    topic_category VARCHAR(60) NOT NULL, -- 'AN_NINH', 'VE_SINH', 'TIEN_ICH', 'THAI_DO_NHAN_VIEN', 'KHAC'
    title VARCHAR(255) NOT NULL,
    content_text TEXT NOT NULL,
    attached_files NVARCHAR(MAX) DEFAULT N'[]',
    
    status VARCHAR(30) NOT NULL DEFAULT 'RECEIVED', -- 'RECEIVED', 'REVIEWING', 'ACCEPTED', 'RESOLVED', 'REJECTED'
    official_response_text TEXT,
    responded_by_staff_id UNIQUEIDENTIFIER REFERENCES users(id),
    responded_at DATETIME2(3),
    
    -- AI PHÂN TÍCH CẢM XÚC (AI SENTIMENT ANALYSIS)
    ai_sentiment VARCHAR(20) DEFAULT 'NEUTRAL', -- 'POSITIVE', 'NEUTRAL', 'NEGATIVE', 'VERY_ANGRY'
    ai_sentiment_score DECIMAL(4, 2), -- Từ -1.00 đến +1.00
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_suggestions_box ON suggestions(box_id, status);

-- 3. BẢNG CHIẾN DỊCH KHẢO SÁT HÀI LÒNG (SATISFACTION SURVEYS)
CREATE TABLE satisfaction_surveys (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    survey_code VARCHAR(60) NOT NULL UNIQUE, -- 'SURVEY-2026-Q3-RESIDENTS'
    title VARCHAR(255) NOT NULL,
    description TEXT,
    target_block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu toàn dự án
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_by UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BẢNG CÂU HỎI KHẢO SÁT (SURVEY QUESTIONS)
CREATE TABLE survey_questions (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    survey_id UNIQUEIDENTIFIER NOT NULL REFERENCES satisfaction_surveys(id),
    question_order INTEGER NOT NULL DEFAULT 1,
    question_text TEXT NOT NULL,
    question_type VARCHAR(30) NOT NULL, -- 'RATING_1_TO_5', 'YES_NO', 'MULTIPLE_CHOICE', 'FREE_TEXT'
    options_json NVARCHAR(MAX) DEFAULT N'[]',
    is_required BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG PHẢN HỒI KHẢO SÁT CỦA CƯ DÂN (SURVEY RESPONSES)
CREATE TABLE survey_responses (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    survey_id UNIQUEIDENTIFIER NOT NULL REFERENCES satisfaction_surveys(id),
    resident_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER REFERENCES apartments(id),
    submitted_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. CHI TIẾT CÂU TRẢ LỜI (SURVEY RESPONSE DETAILS)
CREATE TABLE survey_response_details (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    response_id UNIQUEIDENTIFIER NOT NULL REFERENCES survey_responses(id),
    question_id UNIQUEIDENTIFIER NOT NULL REFERENCES survey_questions(id),
    numeric_score INTEGER, -- 1 đến 5 sao
    selected_option TEXT,
    text_answer TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 15_vehicles_and_anpr_parking.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 15: Đăng Ký Xe, Bãi Đỗ, Thẻ Từ & Tự Động Hóa Nhận Diện Biển Số AI (ANPR)
-- ============================================================================

-- 1. BẢNG PHƯƠNG TIỆN CƯ DÂN (VEHICLES)
CREATE TABLE vehicles (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    owner_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    license_plate VARCHAR(30) NOT NULL UNIQUE, -- '30A-12345', '51H-99988'
    vehicle_category VARCHAR(50) NOT NULL DEFAULT 'MOTORBIKE',
    brand VARCHAR(60), -- 'Honda', 'Toyota', 'VinFast'
    model VARCHAR(60),
    color VARCHAR(40),
    registration_certificate_number VARCHAR(80), -- Số cà vẹt xe
    vehicle_photo_url VARCHAR(500),
    registration_cert_photo_url VARCHAR(500),
    
    monthly_parking_fee DECIMAL(12, 2) NOT NULL DEFAULT 100000.00,
    has_electric_charging_subscription BIT NOT NULL DEFAULT 0,
    is_active BIT NOT NULL DEFAULT 1,
    
    approved_by UNIQUEIDENTIFIER REFERENCES users(id),
    approved_at DATETIME2(3),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME2(3) NULL
);

CREATE INDEX idx_vehicles_plate ON vehicles(license_plate);
CREATE INDEX idx_vehicles_apt ON vehicles(apartment_id, is_active);

-- 2. BẢNG KHU VỰC VÀ VỊ TRÍ ĐỖ XE (PARKING SLOTS)
CREATE TABLE parking_slots (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    block_id UNIQUEIDENTIFIER NOT NULL REFERENCES blocks(id),
    floor_id UNIQUEIDENTIFIER NOT NULL REFERENCES floors(id),
    slot_code VARCHAR(40) NOT NULL UNIQUE, -- 'B1-CAR-042', 'B2-MOTO-105'
    vehicle_category VARCHAR(50) NOT NULL,
    has_ev_charging_station BIT NOT NULL DEFAULT 0,
    is_reserved BIT NOT NULL DEFAULT 0,
    assigned_vehicle_id UNIQUEIDENTIFIER REFERENCES vehicles(id),
    status VARCHAR(30) NOT NULL DEFAULT 'AVAILABLE', -- 'AVAILABLE', 'OCCUPIED', 'MAINTENANCE'
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG THẺ TỪ GỬI XE & THẺ CƯ DÂN (ACCESS CARDS)
CREATE TABLE access_cards (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    card_uid VARCHAR(60) NOT NULL UNIQUE, -- Mã chip RFID / Mifare / NFC
    card_number VARCHAR(40) NOT NULL UNIQUE, -- Mã in trên mặt thẻ
    card_type VARCHAR(40) NOT NULL DEFAULT 'RESIDENT_ALL_ACCESS', -- 'RESIDENT_ALL_ACCESS', 'PARKING_ONLY', 'ELEVATOR_ONLY', 'VISITOR_PASS'
    assigned_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    assigned_apartment_id UNIQUEIDENTIFIER REFERENCES apartments(id),
    assigned_vehicle_id UNIQUEIDENTIFIER REFERENCES vehicles(id),
    
    issued_date DATE NOT NULL,
    expiry_date DATE,
    status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', -- 'ACTIVE', 'LOCKED_TEMPORARY', 'LOST_REPORTED', 'REVOKED'
    deposit_fee DECIMAL(12, 2) DEFAULT 50000.00,
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_access_cards_uid ON access_cards(card_uid);

-- 4. BẢNG NHẬT KÝ RA VÀO BÃI XE TÍCH HỢP AI CAMERA ANPR (PARKING ACCESS LOGS)
CREATE TABLE parking_access_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    access_direction VARCHAR(50) NOT NULL, -- 'IN' hoặc 'OUT'
    log_timestamp DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lane_device_id UNIQUEIDENTIFIER REFERENCES iot_devices(id), -- Camera ANPR tại làn xe
    gate_name VARCHAR(80) NOT NULL, -- 'Cổng 1 - Làn Ô tô Vào', 'Cổng 2 - Làn Xe máy Ra'
    
    card_id UNIQUEIDENTIFIER REFERENCES access_cards(id),
    registered_vehicle_id UNIQUEIDENTIFIER REFERENCES vehicles(id),
    
    -- DỮ LIỆU NHẬN DIỆN BIỂN SỐ TỰ ĐỘNG BẰNG AI (ANPR / LPR)
    detected_license_plate VARCHAR(30) NOT NULL,
    ai_confidence_score DECIMAL(5, 2), -- e.g. 97.80%
    captured_plate_image_url VARCHAR(500) NOT NULL,
    captured_vehicle_overview_image_url VARCHAR(500),
    
    is_visitor_vehicle BIT NOT NULL DEFAULT 0,
    visitor_fee_charged DECIMAL(10, 2) DEFAULT 0.00,
    barrier_auto_opened BIT NOT NULL DEFAULT 1,
    security_guard_override_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Bảo vệ bấm mở cưỡng bức nếu biển số mờ
    notes TEXT
);

CREATE INDEX idx_parking_logs_plate ON parking_access_logs(detected_license_plate, log_timestamp DESC);


/* ================= MODULE: 16_visitors_and_security_gate.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 16: Khách Viếng Thăm (Visitors), Check-in QR & Danh Sách Đen An Ninh
-- ============================================================================

-- 1. BẢNG ĐĂNG KÝ KHÁCH VIẾNG THĂM (VISITOR REGISTRATIONS)
CREATE TABLE visitor_registrations (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    registration_code VARCHAR(60) NOT NULL UNIQUE, -- 'VIS-20260909-089'
    host_resident_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    
    visitor_name VARCHAR(150) NOT NULL,
    visitor_phone VARCHAR(25) NOT NULL,
    visitor_national_id VARCHAR(30),
    expected_arrival_time DATETIME2(3) NOT NULL,
    expected_departure_time DATETIME2(3),
    visit_purpose VARCHAR(200) NOT NULL DEFAULT 'Thăm thân nhân',
    visitor_count INTEGER NOT NULL DEFAULT 1,
    vehicle_license_plate VARCHAR(30),
    
    -- MÃ QR TIẾP ĐÓN NHANH
    qr_access_pass_code VARCHAR(120) NOT NULL UNIQUE,
    qr_pass_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE', -- 'ACTIVE', 'USED', 'EXPIRED', 'REVOKED'
    
    is_pre_approved_by_resident BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_visitors_host ON visitor_registrations(host_resident_user_id, expected_arrival_time DESC);

-- 2. BẢNG LỊCH SỬ CHECK-IN / CHECK-OUT KHÁCH (VISITOR CHECKIN LOGS)
CREATE TABLE visitor_checkin_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    registration_id UNIQUEIDENTIFIER REFERENCES visitor_registrations(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    
    visitor_name VARCHAR(150) NOT NULL,
    visitor_phone VARCHAR(25),
    national_id_number VARCHAR(30),
    
    -- AI OCR GIẤY TỜ TÙY THÂN (NẾU KHÁCH KHÔNG ĐĂNG KÝ TRƯỚC VÀ ĐƯA CCCD TẠI QUẦY)
    id_card_photo_url VARCHAR(500),
    ai_ocr_extracted_data NVARCHAR(MAX),
    
    checkin_time DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checkout_time DATETIME2(3),
    receptionist_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Nhân viên lễ tân tiếp nhận
    security_guard_user_id UNIQUEIDENTIFIER REFERENCES users(id), -- Bảo vệ cổng xác nhận
    
    temp_card_id UNIQUEIDENTIFIER REFERENCES access_cards(id), -- Thẻ khách tạm được phát
    assigned_elevator_access NVARCHAR(MAX) DEFAULT N'[]', -- Tầng được phép đi thang máy
    
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_checkin_time ON visitor_checkin_logs(checkin_time DESC);

-- 3. BẢNG DANH SÁCH ĐEN AN NINH (SECURITY BLACKLIST)
CREATE TABLE security_blacklist (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    full_name VARCHAR(150) NOT NULL,
    national_id_number VARCHAR(30),
    phone_number VARCHAR(25),
    vehicle_license_plate VARCHAR(30),
    reason TEXT NOT NULL, -- 'Trộm cắp', 'Gây rối trật tự', 'Quấy rối cư dân'
    photo_url VARCHAR(500),
    blacklisted_by UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 17_parcels_and_smart_lockers.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 17: Quản Lý Bưu Phẩm, Nhận Diện Nhãn AI OCR & Tủ Đồ Thông Minh (Smart Lockers)
-- ============================================================================

-- 1. BẢNG TỦ ĐỒ GIAO NHẬN THÔNG MINH (SMART PARCEL LOCKERS)
CREATE TABLE smart_lockers (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    block_id UNIQUEIDENTIFIER NOT NULL REFERENCES blocks(id),
    locker_name VARCHAR(100) NOT NULL, -- 'Tủ Giao Hàng Thông Minh Sảnh Tòa A'
    locker_code VARCHAR(50) NOT NULL UNIQUE,
    total_compartments INTEGER NOT NULL DEFAULT 24,
    iot_device_id UNIQUEIDENTIFIER REFERENCES iot_devices(id),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG Ô TỦ ĐỒ (LOCKER COMPARTMENTS)
CREATE TABLE locker_compartments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    locker_id UNIQUEIDENTIFIER NOT NULL REFERENCES smart_lockers(id),
    compartment_number VARCHAR(20) NOT NULL, -- 'A-01', 'A-02'
    size_category VARCHAR(20) NOT NULL DEFAULT 'MEDIUM', -- 'SMALL', 'MEDIUM', 'LARGE'
    is_occupied BIT NOT NULL DEFAULT 0,
    current_parcel_id UNIQUEIDENTIFIER, -- Sẽ liên kết sau
    last_opened_at DATETIME2(3),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_locker_compartment UNIQUE (locker_id, compartment_number)
);

-- 3. BẢNG BƯU PHẨM / KIỆN HÀNG (PARCELS)
CREATE TABLE parcels (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    tracking_number VARCHAR(100) NOT NULL, -- Mã vận đơn
    courier_company VARCHAR(80) NOT NULL, -- 'Shopee Express', 'SPX', 'GHTK', 'GHN', 'ViettelPost', 'J&T'
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    recipient_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    recipient_name VARCHAR(150) NOT NULL,
    recipient_phone VARCHAR(25) NOT NULL,
    
    parcel_photo_url VARCHAR(500) NOT NULL,
    
    -- DỮ LIỆU BÓC TÁCH NHÃN BẰNG AI VISION OCR
    ai_ocr_extracted_text TEXT,
    ai_ocr_raw_result NVARCHAR(MAX),
    ai_ocr_confidence DECIMAL(5, 2),
    
    -- VỊ TRÍ LƯU GIỮ
    stored_location_type VARCHAR(30) NOT NULL DEFAULT 'RECEPTION_DESK', -- 'RECEPTION_DESK', 'SMART_LOCKER', 'SECURITY_BOOTH'
    locker_compartment_id UNIQUEIDENTIFIER REFERENCES locker_compartments(id),
    
    -- MÃ LẤY HÀNG (PICKUP CODE)
    pickup_pin_code VARCHAR(10) NOT NULL, -- Mã PIN 6 số hoặc mã QR
    pickup_qr_code VARCHAR(100) NOT NULL UNIQUE,
    
    status VARCHAR(50) NOT NULL DEFAULT 'RECEIVED_AT_RECEPTION',
    received_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by_staff_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    
    -- CHECK-OUT
    collected_at DATETIME2(3),
    collected_by_name VARCHAR(150),
    collected_signature_url VARCHAR(500), -- Chữ ký nhận tại quầy lễ tân
    checkout_staff_id UNIQUEIDENTIFIER REFERENCES users(id),
    
    notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_parcels_apt ON parcels(apartment_id, status);
CREATE INDEX idx_parcels_tracking ON parcels(tracking_number);
CREATE INDEX idx_parcels_status ON parcels(status, received_at DESC);


/* ================= MODULE: 18_security_patrols_and_assets.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 18: Tuần Tra An Ninh (NFC/QR), Báo Cáo Sự Cố & Quản Lý Tài Sản Thiết Bị
-- ============================================================================

-- 1. BẢNG ĐIỂM KIỂM TRA TUẦN TRA (PATROL CHECKPOINTS)
CREATE TABLE patrol_checkpoints (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    checkpoint_code VARCHAR(60) NOT NULL UNIQUE, -- 'CP-A-ROOF-01', 'CP-B-BASEMENT-04'
    checkpoint_name VARCHAR(150) NOT NULL, -- 'Hộp Van Cứu Hỏa Tầng Mái Tòa A'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    floor_id UNIQUEIDENTIFIER REFERENCES floors(id),
    location_description VARCHAR(255),
    nfc_tag_uid VARCHAR(80) UNIQUE, -- Mã thẻ chip NFC gắn tại hiện trường
    qr_code_value VARCHAR(120) NOT NULL UNIQUE, -- Mã QR dự phòng
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG TUYẾN TUẦN TRA (PATROL ROUTES)
CREATE TABLE patrol_routes (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    route_name VARCHAR(150) NOT NULL, -- 'Tuyến tuần tra An ninh & PCCC Đêm - Tòa A'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    estimated_duration_minutes INTEGER NOT NULL DEFAULT 45,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG ĐIỂM THUỘC TUYẾN (ROUTE CHECKPOINTS MAPPING)
CREATE TABLE patrol_route_checkpoints (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    route_id UNIQUEIDENTIFIER NOT NULL REFERENCES patrol_routes(id),
    checkpoint_id UNIQUEIDENTIFIER NOT NULL REFERENCES patrol_checkpoints(id),
    sequence_order INTEGER NOT NULL DEFAULT 1,
    min_stay_seconds INTEGER NOT NULL DEFAULT 30, -- Yêu cầu dừng kiểm tra tối thiểu bao nhiêu giây
    CONSTRAINT uq_route_checkpoint_order UNIQUE (route_id, sequence_order)
);

-- 4. BẢNG NHẬT KÝ CA TUẦN TRA THỰC TẾ (PATROL LOGS)
CREATE TABLE patrol_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    route_id UNIQUEIDENTIFIER NOT NULL REFERENCES patrol_routes(id),
    guard_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    start_time DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    end_time DATETIME2(3),
    total_checkpoints INTEGER NOT NULL DEFAULT 0,
    scanned_checkpoints INTEGER NOT NULL DEFAULT 0,
    missed_checkpoints INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'IN_PROGRESS', -- 'IN_PROGRESS', 'COMPLETED', 'INCOMPLETE', 'ABORTED'
    notes TEXT
);

-- 5. BẢNG QUÉT TỪNG CHECKPOINT (CHECKPOINT SCAN AUDIT)
CREATE TABLE patrol_scans (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    patrol_log_id UNIQUEIDENTIFIER NOT NULL REFERENCES patrol_logs(id),
    checkpoint_id UNIQUEIDENTIFIER NOT NULL REFERENCES patrol_checkpoints(id),
    scanned_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scan_method VARCHAR(20) NOT NULL DEFAULT 'NFC', -- 'NFC', 'QR_CODE', 'GPS_MANUAL'
    photo_proof_url VARCHAR(500),
    is_abnormal_reported BIT NOT NULL DEFAULT 0,
    abnormal_description TEXT
);

-- 6. BẢNG BÁO CÁO SỰ CỐ AN NINH (SECURITY INCIDENTS)
CREATE TABLE security_incidents (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    incident_code VARCHAR(60) NOT NULL UNIQUE, -- 'INC-20260909-003'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    severity_level VARCHAR(30) NOT NULL DEFAULT 'MEDIUM', -- 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL_POLICE_FIRE'
    incident_time DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reporter_user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    attached_media NVARCHAR(MAX) DEFAULT N'[]',
    action_taken TEXT,
    resolved_at DATETIME2(3),
    status VARCHAR(30) NOT NULL DEFAULT 'INVESTIGATING' -- 'REPORTED', 'INVESTIGATING', 'RESOLVED', 'CLOSED'
);

-- 7. BẢNG ĐỒ THẤT LẠC (LOST & FOUND)
CREATE TABLE lost_and_found (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    item_name VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    found_location VARCHAR(200) NOT NULL,
    found_time DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    item_photo_url VARCHAR(500),
    found_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    status VARCHAR(30) NOT NULL DEFAULT 'UNCLAIMED', -- 'UNCLAIMED', 'CLAIMED', 'DISPOSED'
    claimed_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    claimed_at DATETIME2(3),
    handover_notes TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 8. BẢNG TÀI SẢN & THIẾT BỊ TÒA NHÀ (BUILDING ASSETS)
CREATE TABLE building_assets (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    asset_code VARCHAR(60) NOT NULL UNIQUE, -- 'ELEV-A-01', 'GEN-B-01', 'PUMP-MAIN-01'
    asset_name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL, -- 'ELEVATOR', 'GENERATOR', 'WATER_PUMP', 'HVAC', 'FIRE_ALARM', 'SECURITY_SYSTEM'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    floor_id UNIQUEIDENTIFIER REFERENCES floors(id),
    location_detail VARCHAR(255),
    manufacturer VARCHAR(100),
    model_number VARCHAR(100),
    serial_number VARCHAR(100),
    installed_date DATE,
    warranty_expiry_date DATE,
    maintenance_interval_days INTEGER NOT NULL DEFAULT 90, -- Chu kỳ bảo dưỡng định kỳ (e.g. 90 ngày)
    last_maintenance_date DATE,
    next_maintenance_due_date DATE,
    current_status VARCHAR(30) NOT NULL DEFAULT 'OPERATIONAL', -- 'OPERATIONAL', 'UNDER_MAINTENANCE', 'FAULTY', 'DECOMMISSIONED'
    vendor_contact_info NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 9. BẢNG LỊCH SỬ BẢO DƯỠNG TÀI SẢN THIẾT BỊ (ASSET MAINTENANCE LOGS)
CREATE TABLE asset_maintenance_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    asset_id UNIQUEIDENTIFIER NOT NULL REFERENCES building_assets(id),
    maintenance_type VARCHAR(50) NOT NULL DEFAULT 'PREVENTIVE', -- 'PREVENTIVE', 'CORRECTIVE', 'ANNUAL_CERTIFICATION'
    maintenance_date DATE NOT NULL,
    performed_by_vendor VARCHAR(150),
    lead_technician_name VARCHAR(100),
    total_cost DECIMAL(14, 2) DEFAULT 0.00,
    work_summary TEXT NOT NULL,
    replaced_parts NVARCHAR(MAX) DEFAULT N'[]',
    inspection_certificate_url VARCHAR(500),
    approved_by_manager_id UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);


/* ================= MODULE: 19_staff_operations_and_attendance.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 19: Nhân Sự Vận Hành, Lịch Trực (Lễ Tân, Bảo Vệ, Lao Công) & Chấm Công
-- ============================================================================

-- 1. BẢNG PHÒNG BAN VẬN HÀNH (DEPARTMENTS)
CREATE TABLE departments (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    department_code VARCHAR(40) NOT NULL UNIQUE, -- 'FRONT_DESK', 'SECURITY', 'HOUSEKEEPING', 'TECHNICAL', 'ACCOUNTING'
    department_name VARCHAR(120) NOT NULL, -- 'Bộ phận Lễ Tân', 'Bộ phận An Ninh', 'Đội Vệ Sinh Lao Công', 'Đội Kỹ Thuật'
    head_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG HỒ SƠ NHÂN VIÊN (STAFF PROFILES)
CREATE TABLE staff_profiles (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL UNIQUE REFERENCES users(id),
    employee_code VARCHAR(40) NOT NULL UNIQUE, -- 'EMP-NV-0042'
    department_id UNIQUEIDENTIFIER NOT NULL REFERENCES departments(id),
    job_title VARCHAR(100) NOT NULL, -- 'Nhân viên Lễ tân', 'Bảo vệ ca đêm', 'Nhân viên vệ sinh'
    hire_date DATE NOT NULL,
    contract_type VARCHAR(50) DEFAULT 'FULL_TIME',
    assigned_block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu phụ trách toàn khu
    base_salary DECIMAL(14, 2),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG LOẠI CA TRỰC (SHIFT TYPES)
CREATE TABLE shift_types (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    shift_code VARCHAR(30) NOT NULL UNIQUE, -- 'CA_SANG', 'CA_CHIEU', 'CA_DEM', 'HANH_CHINH'
    shift_name VARCHAR(80) NOT NULL, -- 'Ca Sáng (06:00 - 14:00)', 'Ca Chiều (14:00 - 22:00)'
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_minutes INTEGER DEFAULT 60,
    is_overnight BIT NOT NULL DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BẢNG PHÂN LỊCH TRỰC NHÂN VIÊN (STAFF SCHEDULES)
CREATE TABLE staff_schedules (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    staff_profile_id UNIQUEIDENTIFIER NOT NULL REFERENCES staff_profiles(id),
    shift_type_id UNIQUEIDENTIFIER NOT NULL REFERENCES shift_types(id),
    work_date DATE NOT NULL,
    assigned_location VARCHAR(150), -- 'Sảnh chính Tòa A', 'Bốt trực cổng số 1'
    status VARCHAR(30) NOT NULL DEFAULT 'SCHEDULED', -- 'SCHEDULED', 'COMPLETED', 'ABSENT', 'SWAPPED'
    notes TEXT,
    created_by UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_staff_work_date UNIQUE (staff_profile_id, work_date)
);

CREATE INDEX idx_schedules_date ON staff_schedules(work_date, shift_type_id);

-- 5. BẢNG CHẤM CÔNG (STAFF ATTENDANCE)
CREATE TABLE staff_attendance (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    staff_profile_id UNIQUEIDENTIFIER NOT NULL REFERENCES staff_profiles(id),
    schedule_id UNIQUEIDENTIFIER REFERENCES staff_schedules(id),
    attendance_date DATE NOT NULL,
    
    clock_in_time DATETIME2(3),
    clock_in_method VARCHAR(30) DEFAULT 'GPS_APP', -- 'FACE_RECOGNITION', 'QR_SCAN', 'GPS_APP', 'CARD_SCAN'
    clock_in_photo_url VARCHAR(500),
    is_clock_in_late BIT NOT NULL DEFAULT 0,
    late_minutes INTEGER DEFAULT 0,
    
    clock_out_time DATETIME2(3),
    clock_out_method VARCHAR(30) DEFAULT 'GPS_APP',
    is_clock_out_early BIT NOT NULL DEFAULT 0,
    early_leave_minutes INTEGER DEFAULT 0,
    
    overtime_hours DECIMAL(4, 2) DEFAULT 0.00,
    approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. BẢNG NHIỆM VỤ & CHECKLIST HÀNG NGÀY CHO LAO CÔNG / KỸ THUẬT (STAFF TASKS)
CREATE TABLE staff_tasks (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    task_title VARCHAR(200) NOT NULL, -- 'Vệ sinh hành lang Tầng 3 đến Tầng 6 Tòa A'
    task_description TEXT,
    department_id UNIQUEIDENTIFIER NOT NULL REFERENCES departments(id),
    assigned_staff_id UNIQUEIDENTIFIER REFERENCES staff_profiles(id),
    assigned_block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    task_priority VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
    due_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'ASSIGNED', -- 'ASSIGNED', 'IN_PROGRESS', 'COMPLETED', 'VERIFIED'
    
    checklist_items NVARCHAR(MAX) DEFAULT N'[]', -- [{"item": "Quét rác", "done": 1}, {"item": "Lau sàn", "done": 0}]
    proof_photo_url VARCHAR(500),
    verified_by_supervisor_id UNIQUEIDENTIFIER REFERENCES users(id),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 7. BẢNG BIÊN BẢN BÀN GIAO CA TRỰC (SHIFT HANDOVER LOGS)
CREATE TABLE shift_handover_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    department_id UNIQUEIDENTIFIER NOT NULL REFERENCES departments(id),
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id),
    outgoing_shift_id UNIQUEIDENTIFIER NOT NULL REFERENCES shift_types(id),
    outgoing_staff_id UNIQUEIDENTIFIER NOT NULL REFERENCES staff_profiles(id),
    incoming_staff_id UNIQUEIDENTIFIER NOT NULL REFERENCES staff_profiles(id),
    handover_time DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    pending_tasks_summary TEXT, -- Việc chưa xử lý xong cần ca sau tiếp tục
    visitor_issues_summary TEXT,
    equipment_status_summary TEXT, -- Tình trạng bộ đàm, chìa khóa tổng, màn hình camera
    notes TEXT,
    is_accepted_by_incoming_staff BIT NOT NULL DEFAULT 1
);


/* ================= MODULE: 20_ai_services_and_logs.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 20: Hệ Sinh Thái AI Thông Minh (AI Smart Building Engine & Logs)
-- Bao gồm: Chatbot RAG Cư Dân, Vision OCR (Điện/Nước, Biển Số, Bưu Phẩm, CCCD), Triage Ticket, Cảnh Báo Bất Thường
-- ============================================================================

-- 1. BẢNG ĐĂNG KÝ MÔ HÌNH AI (AI MODELS REGISTRY)
CREATE TABLE ai_models (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    model_code VARCHAR(80) NOT NULL UNIQUE, -- 'gpt-4o-mini', 'gemini-1.5-flash', 'yolov8-anpr', 'trocr-meters-v2'
    provider VARCHAR(50) NOT NULL DEFAULT 'OPENAI',
    model_name VARCHAR(120) NOT NULL,
    task_category VARCHAR(50) NOT NULL,
    api_endpoint VARCHAR(255),
    cost_per_1k_tokens DECIMAL(10, 6) DEFAULT 0.000000,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. BẢNG QUẢN LÝ PROMPT HỆ THỐNG (AI PROMPTS & TEMPLATES)
CREATE TABLE ai_prompts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    prompt_code VARCHAR(80) NOT NULL UNIQUE, -- 'PROMPT_RESIDENT_RAG_ASSISTANT', 'PROMPT_TICKET_TRIAGE'
    task_type VARCHAR(50) NOT NULL,
    system_prompt_text TEXT NOT NULL,
    temperature DECIMAL(3, 2) NOT NULL DEFAULT 0.20,
    version INTEGER NOT NULL DEFAULT 1,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. CƠ SỞ TRI THỨC HỎI ĐÁP RAG (AI KNOWLEDGE CHUNKS FOR EMBEDDINGS)
CREATE TABLE ai_knowledge_documents (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    doc_title VARCHAR(200) NOT NULL, -- 'Nội quy Chung Cư Tòa Nhà A & B 2026', 'Biểu phí Dịch Vụ Cập Nhật'
    category VARCHAR(60) NOT NULL, -- 'REGULATION', 'FEE_SCHEDULE', 'FIRE_SAFETY_GUIDE'
    block_id UNIQUEIDENTIFIER REFERENCES blocks(id), -- NULL nếu áp dụng toàn khu
    original_file_url VARCHAR(500),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE ai_knowledge_chunks (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    document_id UNIQUEIDENTIFIER NOT NULL REFERENCES ai_knowledge_documents(id),
    chunk_index INTEGER NOT NULL,
    chunk_content TEXT NOT NULL,
    token_count INTEGER NOT NULL DEFAULT 0,
    metadata NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_chunks_doc ON ai_knowledge_chunks(document_id);

-- 4. BẢNG PHIÊN HỘI THOẠI TRỢ LÝ ẢO CƯ DÂN & LỄ TÂN (AI CHATBOT SESSIONS)
CREATE TABLE ai_conversations (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    user_id UNIQUEIDENTIFIER NOT NULL REFERENCES users(id),
    apartment_id UNIQUEIDENTIFIER REFERENCES apartments(id),
    title VARCHAR(150) NOT NULL DEFAULT 'Hội thoại mới',
    started_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_archived BIT NOT NULL DEFAULT 0
);

CREATE TABLE ai_messages (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    conversation_id UNIQUEIDENTIFIER NOT NULL REFERENCES ai_conversations(id),
    sender_type VARCHAR(20) NOT NULL, -- 'USER', 'ASSISTANT', 'SYSTEM'
    message_content TEXT NOT NULL,
    retrieved_knowledge_chunk_ids NVARCHAR(MAX) DEFAULT N'[]', -- Nguồn trích dẫn (Citations)
    prompt_tokens INTEGER DEFAULT 0,
    completion_tokens INTEGER DEFAULT 0,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG NHẬT KÝ NHẬN DIỆN THỊ GIÁC AI OCR (AI VISION & OCR AUDIT LOGS)
CREATE TABLE ai_ocr_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    task_type VARCHAR(50) NOT NULL, -- 'METER_OCR', 'ANPR_LICENSE_PLATE', 'PARCEL_OCR', 'ID_CARD_EKYC'
    input_image_url VARCHAR(500) NOT NULL,
    model_code VARCHAR(80) NOT NULL,
    
    extracted_text_result TEXT,
    structured_json_result NVARCHAR(MAX) NOT NULL DEFAULT N'{}', -- Số nhận diện được, bounding boxes
    confidence_score DECIMAL(5, 2) NOT NULL, -- % tin cậy
    latency_ms INTEGER,
    
    -- XÁC MINH CỦA CON NGƯỜI (HUMAN-IN-THE-LOOP FOR ACTIVE LEARNING)
    is_human_verified BIT NOT NULL DEFAULT 0,
    verified_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    is_correct_prediction BIT,
    human_corrected_value VARCHAR(255),
    
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ai_ocr_task ON ai_ocr_logs(task_type, created_at DESC);

-- 6. BẢNG PHÂN TÍCH TỰ ĐỘNG SỰ CỐ TICKET (AI SMART TICKET TRIAGE)
CREATE TABLE ai_ticket_triages (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    ticket_id UNIQUEIDENTIFIER NOT NULL UNIQUE REFERENCES tickets(id),
    suggested_category_id UNIQUEIDENTIFIER REFERENCES ticket_categories(id),
    suggested_priority VARCHAR(50) NOT NULL,
    urgency_score DECIMAL(4, 2) NOT NULL, -- 0.00 đến 1.00
    resident_sentiment VARCHAR(20), -- 'ANGRY', 'CALM', 'PANIC'
    suggested_technician_id UNIQUEIDENTIFIER REFERENCES users(id),
    reasoning_summary TEXT NOT NULL,
    applied_by_system BIT NOT NULL DEFAULT 1,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 7. BẢNG CẢNH BÁO BẤT THƯỜNG CHỈ SỐ ĐIỆN / NƯỚC (AI ANOMALY DETECTION ALERTS)
CREATE TABLE ai_anomaly_alerts (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    alert_code VARCHAR(60) NOT NULL UNIQUE, -- 'ANOMALY-202609-WM-004'
    meter_id UNIQUEIDENTIFIER NOT NULL REFERENCES meters(id),
    apartment_id UNIQUEIDENTIFIER NOT NULL REFERENCES apartments(id),
    billing_period VARCHAR(7) NOT NULL,
    
    anomaly_type VARCHAR(60) NOT NULL, -- 'SPIKE_WATER_LEAKAGE_RISK', 'SPIKE_ELECTRIC_OVERLOAD', 'SUSPECTED_REVERSE_FLOW'
    baseline_average_usage DECIMAL(10, 2) NOT NULL, -- Mức dùng trung bình 3 tháng trước
    current_recorded_usage DECIMAL(10, 2) NOT NULL, -- Mức dùng tháng này nhảy vọt
    percentage_increase DECIMAL(6, 2) NOT NULL, -- Tăng bao nhiêu %
    
    severity_level VARCHAR(20) NOT NULL DEFAULT 'WARNING', -- 'INFO', 'WARNING', 'CRITICAL_LEAK'
    notification_sent_to_resident BIT NOT NULL DEFAULT 0,
    verified_by_technician BIT NOT NULL DEFAULT 0,
    technician_findings TEXT,
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_anomaly_meter ON ai_anomaly_alerts(meter_id, created_at DESC);


/* ================= MODULE: 21_audit_and_dev_tools.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 21: Audit Trail (CDC), Cấu Hình Hệ Thống, Feature Flags & DevTools
-- ============================================================================

-- 1. BẢNG AUDIT TRAIL TOÀN DIỆN (SYSTEM AUDIT LOGS - CHANGE DATA CAPTURE)
CREATE TABLE audit_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    table_name VARCHAR(80) NOT NULL,
    record_id UNIQUEIDENTIFIER NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'INSERT', 'UPDATE', 'DELETE', 'SOFT_DELETE'
    performed_by_user_id UNIQUEIDENTIFIER REFERENCES users(id),
    client_ip_address VARCHAR(45),
    user_agent TEXT,
    old_data NVARCHAR(MAX), -- Trạng thái trước khi sửa
    new_data NVARCHAR(MAX), -- Trạng thái sau khi sửa
    changed_fields NVARCHAR(MAX), -- Danh sách các cột bị thay đổi
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_table_record ON audit_logs(table_name, record_id);
CREATE INDEX idx_audit_created_at ON audit_logs(created_at DESC);

-- 2. BẢNG CẤU HÌNH ĐỘNG TOÀN HỆ THỐNG (SYSTEM CONFIGURATIONS)
CREATE TABLE system_configs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    data_type VARCHAR(30) NOT NULL DEFAULT 'STRING', -- 'STRING', 'INTEGER', 'BIT', 'JSON'
    config_group VARCHAR(50) NOT NULL DEFAULT 'GENERAL', -- 'BILLING', 'AI', 'NOTIFICATION', 'SECURITY'
    description TEXT,
    is_public_to_frontend BIT NOT NULL DEFAULT 0,
    updated_by UNIQUEIDENTIFIER REFERENCES users(id),
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 3. BẢNG CỜ TÍNH NĂNG CHO DEVELOPER (FEATURE FLAGS)
CREATE TABLE feature_flags (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    flag_key VARCHAR(80) NOT NULL UNIQUE, -- 'FEATURE_AI_OCR_METER', 'FEATURE_COMMUNITY_POLLS', 'FEATURE_AUTO_BARRIER'
    flag_name VARCHAR(150) NOT NULL,
    description TEXT,
    is_enabled BIT NOT NULL DEFAULT 0,
    rollout_percentage INTEGER NOT NULL DEFAULT 100 CHECK (rollout_percentage BETWEEN 0 AND 100),
    target_block_ids NVARCHAR(MAX) DEFAULT N'[]', -- Áp dụng thử nghiệm cho Tòa A trước khi bật cho Tòa B
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 4. BẢNG QUẢN LÝ TIẾN TRÌNH CRON JOBS ĐỊNH KỲ (SCHEDULED JOBS)
CREATE TABLE scheduled_jobs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    job_code VARCHAR(80) NOT NULL UNIQUE, -- 'JOB_GENERATE_MONTHLY_INVOICES', 'JOB_SEND_DEBT_REMINDERS', 'JOB_AI_DETECT_LEAKAGE'
    job_name VARCHAR(150) NOT NULL,
    cron_expression VARCHAR(60) NOT NULL, -- '0 0 1 * *' (0h ngày 1 hàng tháng)
    is_active BIT NOT NULL DEFAULT 1,
    last_run_at DATETIME2(3),
    last_run_status VARCHAR(30), -- 'SUCCESS', 'FAILED', 'RUNNING'
    next_run_at DATETIME2(3),
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. BẢNG NHẬT KÝ CHẠY CRON JOB (SCHEDULED JOB LOGS)
CREATE TABLE scheduled_job_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    job_id UNIQUEIDENTIFIER NOT NULL REFERENCES scheduled_jobs(id),
    started_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME2(3),
    status VARCHAR(30) NOT NULL, -- 'SUCCESS', 'FAILED'
    items_processed INTEGER DEFAULT 0,
    error_message TEXT,
    execution_duration_ms INTEGER
);

-- 6. BẢNG NHẬT KÝ LỖI HỆ THỐNG (SYSTEM ERROR LOGS)
CREATE TABLE system_error_logs (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    service_name VARCHAR(80) NOT NULL DEFAULT 'BACKEND_API',
    error_level VARCHAR(20) NOT NULL DEFAULT 'ERROR', -- 'WARN', 'ERROR', 'FATAL'
    error_message TEXT NOT NULL,
    stack_trace TEXT,
    request_url VARCHAR(500),
    http_method VARCHAR(10),
    user_id UNIQUEIDENTIFIER REFERENCES users(id),
    context_payload NVARCHAR(MAX) DEFAULT N'{}',
    created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_error_logs_created ON system_error_logs(created_at DESC);



/* ================= SQL SERVER COMPATIBILITY SAFETY NET ================= */
GO
-- SQL Server không cho phép nhiều đường dẫn ON DELETE CASCADE.
-- Các bảng dưới đây là fallback nếu một client đã dừng giữa chừng ở module trước.
IF OBJECT_ID(N'dbo.parcels', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.parcels (
        id UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWID(),
        tracking_number VARCHAR(100) NOT NULL,
        courier_company VARCHAR(80) NOT NULL,
        apartment_id UNIQUEIDENTIFIER NOT NULL,
        recipient_user_id UNIQUEIDENTIFIER NULL,
        recipient_name VARCHAR(150) NOT NULL,
        recipient_phone VARCHAR(25) NOT NULL,
        parcel_photo_url VARCHAR(500) NOT NULL,
        ai_ocr_extracted_text TEXT NULL,
        ai_ocr_raw_result NVARCHAR(MAX) NULL,
        ai_ocr_confidence DECIMAL(5,2) NULL,
        stored_location_type VARCHAR(30) NOT NULL DEFAULT 'RECEPTION_DESK',
        locker_compartment_id UNIQUEIDENTIFIER NULL,
        pickup_pin_code VARCHAR(10) NOT NULL,
        pickup_qr_code VARCHAR(100) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'RECEIVED_AT_RECEPTION',
        received_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
        received_by_staff_id UNIQUEIDENTIFIER NOT NULL,
        collected_at DATETIME2(3) NULL,
        collected_by_name VARCHAR(150) NULL,
        collected_signature_url VARCHAR(500) NULL,
        checkout_staff_id UNIQUEIDENTIFIER NULL,
        notes TEXT NULL,
        created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
END;
GO
IF OBJECT_ID(N'dbo.parking_access_logs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.parking_access_logs (
        id UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWID(),
        access_direction VARCHAR(50) NOT NULL,
        log_timestamp DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
        lane_device_id UNIQUEIDENTIFIER NULL,
        gate_name VARCHAR(80) NOT NULL,
        card_id UNIQUEIDENTIFIER NULL,
        registered_vehicle_id UNIQUEIDENTIFIER NULL,
        detected_license_plate VARCHAR(30) NOT NULL,
        ai_confidence_score DECIMAL(5,2) NULL,
        captured_plate_image_url VARCHAR(500) NOT NULL,
        captured_vehicle_overview_image_url VARCHAR(500) NULL,
        is_visitor_vehicle BIT NOT NULL DEFAULT 0,
        visitor_fee_charged DECIMAL(10,2) DEFAULT 0.00,
        barrier_auto_opened BIT NOT NULL DEFAULT 1,
        security_guard_override_user_id UNIQUEIDENTIFIER NULL,
        notes TEXT NULL
    );
END;
GO
IF OBJECT_ID(N'dbo.ai_models', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ai_models (
        id UNIQUEIDENTIFIER NOT NULL PRIMARY KEY DEFAULT NEWID(),
        model_code VARCHAR(80) NOT NULL UNIQUE,
        provider VARCHAR(50) NOT NULL DEFAULT 'OPENAI',
        model_name VARCHAR(120) NOT NULL,
        task_category VARCHAR(50) NOT NULL,
        api_endpoint VARCHAR(255) NULL,
        cost_per_1k_tokens DECIMAL(10,6) DEFAULT 0.000000,
        is_active BIT NOT NULL DEFAULT 1,
        created_at DATETIME2(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
END;
GO

/* ================= MODULE: 22_views_and_analytics.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 22: Báo Cáo, Thống Kê Doanh Thu & Dashboards Vận Hành (Views & Analytics)
-- ============================================================================

-- 1. VIEW TỔNG HỢP DOANH THU & CÔNG NỢ THEO KỲ VÀ THEO TÒA NHÀ
GO
CREATE OR ALTER VIEW v_revenue_by_block_and_period AS
SELECT 
    b.id AS block_id,
    b.block_code,
    b.block_name,
    i.billing_period,
    COUNT(DISTINCT i.id) AS total_invoices_issued,
    COALESCE(SUM(i.total_amount), 0) AS total_billed_amount,
    COALESCE(SUM(i.paid_amount), 0) AS total_collected_amount,
    COALESCE(SUM(i.remaining_balance), 0) AS total_outstanding_debt,
    CASE 
        WHEN SUM(i.total_amount) > 0 THEN 
            ROUND((SUM(i.paid_amount) / SUM(i.total_amount) * 100.0), 2)
        ELSE 0.00 
    END AS collection_rate_percentage
FROM blocks b
LEFT JOIN apartments a ON a.block_id = b.id AND a.deleted_at IS NULL
LEFT JOIN invoices i ON i.apartment_id = a.id AND i.deleted_at IS NULL
GROUP BY b.id, b.block_code, b.block_name, i.billing_period;

-- 2. VIEW CƠ CẤU DOANH THU THEO TỪNG LOẠI DỊCH VỤ
GO
CREATE OR ALTER VIEW v_revenue_by_service_type AS
SELECT 
    ii.service_code,
    ii.item_description,
    i.billing_period,
    COUNT(ii.id) AS item_count,
    COALESCE(SUM(ii.amount_before_tax), 0) AS subtotal_amount,
    COALESCE(SUM(ii.vat_amount), 0) AS total_vat,
    COALESCE(SUM(ii.environmental_fee_amount), 0) AS total_env_fee,
    COALESCE(SUM(ii.total_line_amount), 0) AS grand_total_amount
FROM invoice_items ii
JOIN invoices i ON i.id = ii.invoice_id
WHERE i.deleted_at IS NULL
GROUP BY ii.service_code, ii.item_description, i.billing_period;

-- 3. VIEW TỶ LỆ LẤP ĐẦY CĂN HỘ (OCCUPANCY RATE) THEO TỪNG TÒA
GO
CREATE OR ALTER VIEW v_apartment_occupancy_rate AS
SELECT 
    b.id AS block_id,
    b.block_code,
    b.block_name,
    COUNT(a.id) AS total_units,
    COUNT(CASE WHEN a.status = 'OCCUPIED' THEN 1 END) AS occupied_units,
    COUNT(CASE WHEN a.status = 'VACANT' THEN 1 END) AS vacant_units,
    COUNT(CASE WHEN a.status = 'UNDER_MAINTENANCE' THEN 1 END) AS maintenance_units,
    ROUND(
        (CAST(COUNT(CASE WHEN a.status = 'OCCUPIED' THEN 1 END) AS DECIMAL(18,4)) / 
        NULLIF(COUNT(a.id), 0) * 100.0), 2
    ) AS occupancy_percentage
FROM blocks b
LEFT JOIN apartments a ON a.block_id = b.id AND a.deleted_at IS NULL
WHERE b.deleted_at IS NULL
GROUP BY b.id, b.block_code, b.block_name;

-- 4. VIEW DASHBOARD LỄ TÂN (RECEPTIONIST LIVE DESK)
GO
CREATE OR ALTER VIEW v_receptionist_live_dashboard AS
SELECT 
    (SELECT COUNT(*) FROM parcels WHERE status = 'RECEIVED_AT_RECEPTION') AS uncollected_parcels_count,
    (SELECT COUNT(*) FROM parcels WHERE CAST(received_at AS date) = CAST(GETDATE() AS date)) AS parcels_received_today,
    (SELECT COUNT(*) FROM visitor_registrations WHERE CAST(expected_arrival_time AS date) = CAST(GETDATE() AS date) AND qr_pass_status = 'ACTIVE') AS expected_visitors_today,
    (SELECT COUNT(*) FROM visitor_checkin_logs WHERE CAST(checkin_time AS date) = CAST(GETDATE() AS date) AND checkout_time IS NULL) AS current_visitors_in_building;

-- 5. VIEW DASHBOARD AN NINH & BÃI XE (SECURITY & PARKING LIVE DESK)
GO
CREATE OR ALTER VIEW v_security_live_dashboard AS
SELECT 
    (SELECT COUNT(*) FROM parking_access_logs WHERE CAST(log_timestamp AS date) = CAST(GETDATE() AS date) AND access_direction = 'IN') AS vehicles_entered_today,
    (SELECT COUNT(*) FROM parking_access_logs WHERE CAST(log_timestamp AS date) = CAST(GETDATE() AS date) AND access_direction = 'OUT') AS vehicles_exited_today,
    (SELECT COUNT(*) FROM security_incidents WHERE status IN ('REPORTED', 'INVESTIGATING')) AS active_incidents_count,
    (SELECT COUNT(*) FROM patrol_logs WHERE CAST(start_time AS date) = CAST(GETDATE() AS date) AND status = 'COMPLETED') AS completed_patrols_today;

-- 6. VIEW THỐNG KÊ HIỆU QUẢ XỬ LÝ SỰ CỐ TICKET & SLA
GO
CREATE OR ALTER VIEW v_ticket_sla_performance AS
SELECT 
    tc.category_code,
    tc.category_name,
    COUNT(t.id) AS total_tickets,
    COUNT(CASE WHEN t.status = 'CLOSED' THEN 1 END) AS resolved_tickets,
    ROUND(AVG(tr.rating_stars), 2) AS average_resident_rating,
    COUNT(CASE WHEN t.resolved_at > t.sla_deadline THEN 1 END) AS sla_breached_count
FROM ticket_categories tc
LEFT JOIN tickets t ON t.category_id = tc.id AND t.deleted_at IS NULL
LEFT JOIN ticket_ratings tr ON tr.ticket_id = t.id
GROUP BY tc.category_code, tc.category_name;

GO

/* ================= MODULE: 24_seed_data_complete.sql ================= */
-- ============================================================================
-- HỆ THỐNG QUẢN LÝ CHUNG CƯ & TÒA NHÀ THÔNG MINH (SMART APARTMENT MANAGEMENT SYSTEM)
-- File 24: Dữ Liệu Khởi Tạo & Mẫu Kiểm Thử Hoàn Chỉnh (Seed Data Complete)
-- ============================================================================

-- 1. SEED ROLES HỆ THỐNG
INSERT INTO roles (id, role_code, role_name, description, is_system_role)
VALUES 
    ('00000000-0000-0000-0000-000000000001', 'SUPER_ADMIN', 'Quản Trị Viên Cấp Cao', 'Toàn quyền truy cập hệ thống và cấu hình', 1),
    ('00000000-0000-0000-0000-000000000002', 'BUILDING_MANAGER', 'Ban Quản Lý Tòa Nhà', 'Giám sát vận hành, phê duyệt chi phí, hợp đồng', 1),
    ('00000000-0000-0000-0000-000000000003', 'ACCOUNTANT', 'Kế Toán Tòa Nhà', 'Quản lý hóa đơn, chốt sổ, thu tiền, đối soát', 1),
    ('00000000-0000-0000-0000-000000000004', 'RECEPTIONIST', 'Nhân Viên Lễ Tân', 'Tiếp đón khách, nhận bưu phẩm, hỗ trợ cư dân', 1),
    ('00000000-0000-0000-0000-000000000005', 'SECURITY_GUARD', 'Nhân Viên Bảo Vệ', 'Kiểm soát ra vào, tuần tra, giám sát camera ANPR', 1),
    ('00000000-0000-0000-0000-000000000006', 'TECHNICIAN', 'Nhân Viên Kỹ Thuật', 'Xử lý ticket sự cố, bảo trì điện nước thang máy', 1),
    ('00000000-0000-0000-0000-000000000007', 'CLEANER', 'Nhân Viên Vệ Sinh', 'Thực hiện công việc vệ sinh hành lang, sảnh, rác', 1),
    ('00000000-0000-0000-0000-000000000008', 'RESIDENT_OWNER', 'Cư Dân Chủ Hộ', 'Chủ sở hữu căn hộ, biểu quyết, thanh toán', 1),
    ('00000000-0000-0000-0000-000000000009', 'RESIDENT_MEMBER', 'Thành Viên Trong Hộ', 'Thành viên gia đình cư dân', 1),
    ('00000000-0000-0000-0000-000000000010', 'DEVELOPER', 'Kỹ Sư Phần Mềm (Dev)', 'Quản lý API Keys, Webhooks, Feature Flags, Logs', 1)
;

-- 2. SEED ĐỐI TÁC TÍCH HỢP BÊN NGOÀI (EXTERNAL PROVIDERS)
INSERT INTO external_providers (provider_code, provider_name, service_group, base_url, auth_type)
VALUES
    ('VNPAY', 'Cổng Thanh Toán VNPAY-QR', 'PAYMENT_GATEWAY', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html', 'HMAC_SHA256'),
    ('VIETQR', 'Cổng Đối Soát Tự Động VietQR Napas', 'PAYMENT_GATEWAY', 'https://api.vietqr.io/v2', 'API_KEY'),
    ('VIETTEL_SINVOICE', 'Hóa Đơn Điện Tử Viettel S-Invoice', 'E_INVOICE', 'https://sinvoice.viettel.vn/api/v1', 'BEARER_TOKEN'),
    ('ZALO_ZNS', 'Dịch Vụ Tin Nhắn Zalo ZNS Thông Báo Phí', 'MESSAGING', 'https://business.openapi.zalo.me', 'OAUTH2'),
    ('SMART_CA', 'Chứng Thư Số Từ Xa VNPT SmartCA', 'E_SIGNATURE', 'https://smartca.vnpt.vn/api/v1', 'BEARER_TOKEN')
;

-- 3. SEED KHỐI TÒA NHÀ (BLOCKS: TÒA A VÀ TÒA B)
INSERT INTO blocks (id, block_code, block_name, total_floors, total_basements, total_apartments, address_line)
VALUES
    ('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'BLOCK_A', 'Tòa Nhà A - Ruby Tower', 25, 2, 200, 'Khu Đô Thị Smart City, Block A'),
    ('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'BLOCK_B', 'Tòa Nhà B - Sapphire Tower', 30, 2, 250, 'Khu Đô Thị Smart City, Block B')
;

-- 4. SEED KHÔNG GIAN CỘNG ĐỒNG THEO TÒA (COMMUNITY SPACES)
-- "Cư dân thuộc tòa nhà A thì có cộng đồng A, thuộc tòa B có cộng đồng B"
INSERT INTO community_spaces (id, space_name, space_code, scope_type, block_id, description)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'Cộng Đồng Cư Dân Tòa Nhà A (Ruby)', 'COMMUNITY_BLOCK_A', 'BLOCK_SPECIFIC', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Kênh giao lưu, trao đổi, biểu quyết dành riêng cho cư dân sống tại Tòa Nhà A'),
    ('22222222-2222-2222-2222-222222222222', 'Cộng Đồng Cư Dân Tòa Nhà B (Sapphire)', 'COMMUNITY_BLOCK_B', 'BLOCK_SPECIFIC', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'Kênh giao lưu, trao đổi, biểu quyết dành riêng cho cư dân sống tại Tòa Nhà B'),
    ('33333333-3333-3333-3333-333333333333', 'Diễn Đàn Chung Cư Smart Building (Toàn Khu)', 'COMMUNITY_ALL', 'ALL_BLOCKS', NULL, 'Không gian thảo luận chung cho toàn bộ các tháp tòa nhà')
;

-- 5. SEED TẦNG & CĂN HỘ MẪU CHO TÒA A & TÒA B
INSERT INTO floors (id, block_id, floor_number, floor_code, floor_name, floor_type)
VALUES
    ('fa010000-0000-0000-0000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 12, 'A-F12', 'Tầng 12 - Tòa A', 'RESIDENTIAL'),
    ('fb010000-0000-0000-0000-000000000001', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 8, 'B-F08', 'Tầng 08 - Tòa B', 'RESIDENTIAL')
;

INSERT INTO apartments (id, block_id, floor_id, apartment_number, room_type, gross_floor_area_sqm, net_usable_area_sqm, bedroom_count, status)
VALUES
    ('aa001204-0000-0000-0000-000000000001', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'fa010000-0000-0000-0000-000000000001', 'A-1204', '2_BEDROOM', 75.50, 70.20, 2, 'OCCUPIED'),
    ('bb000802-0000-0000-0000-000000000001', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'fb010000-0000-0000-0000-000000000001', 'B-0802', '3_BEDROOM', 98.00, 92.50, 3, 'OCCUPIED')
;

-- 6. SEED BIỂU GIÁ DỊCH VỤ & BẬC THANG ĐIỆN / NƯỚC THEO QUY ĐỊNH
INSERT INTO service_pricing_configs (id, service_code, service_name, meter_type, billing_type, unit_name, fixed_unit_price, vat_percentage, environmental_protection_fee_pct, effective_from_date)
VALUES
    ('c0000001-0000-0000-0000-000000000001', 'ELECTRICITY_RESIDENTIAL', 'Điện Sinh Hoạt Bậc Thang', 'ELECTRICITY', 'TIERED_USAGE', 'kWh', 0, 10.00, 0, '2026-01-01'),
    ('c0000002-0000-0000-0000-000000000002', 'WATER_RESIDENTIAL', 'Nước Sinh Hoạt Định Mức', 'COLD_WATER', 'TIERED_USAGE', 'm3', 0, 5.00, 10.00, '2026-01-01'),
    ('c0000003-0000-0000-0000-000000000003', 'MANAGEMENT_FEE', 'Phí Dịch Vụ Quản Lý Chung Cư', NULL, 'UNIT_PRICE_USAGE', 'm2/tháng', 14000.00, 10.00, 0, '2026-01-01'),
    ('c0000004-0000-0000-0000-000000000004', 'PARKING_MOTORBIKE', 'Phí Trông Giữ Xe Máy Tháng', NULL, 'FIXED_MONTHLY', 'xe/tháng', 120000.00, 10.00, 0, '2026-01-01'),
    ('c0000005-0000-0000-0000-000000000005', 'PARKING_CAR', 'Phí Trông Giữ Ô Tô Tháng', NULL, 'FIXED_MONTHLY', 'xe/tháng', 1500000.00, 10.00, 0, '2026-01-01')
;

-- SEED CÁC BẬC THANG GIÁ ĐIỆN (BIỂU GIÁ EVN)
INSERT INTO pricing_tiers (pricing_config_id, tier_order, tier_name, min_usage_threshold, max_usage_threshold, unit_price)
VALUES
    ('c0000001-0000-0000-0000-000000000001', 1, 'Bậc 1: Cho kWh từ 0 - 50', 0, 50, 1806.00),
    ('c0000001-0000-0000-0000-000000000001', 2, 'Bậc 2: Cho kWh từ 51 - 100', 50, 100, 1866.00),
    ('c0000001-0000-0000-0000-000000000001', 3, 'Bậc 3: Cho kWh từ 101 - 200', 100, 200, 2167.00),
    ('c0000001-0000-0000-0000-000000000001', 4, 'Bậc 4: Cho kWh từ 201 - 300', 200, 300, 2729.00),
    ('c0000001-0000-0000-0000-000000000001', 5, 'Bậc 5: Cho kWh từ 301 - 400', 300, 400, 3050.00),
    ('c0000001-0000-0000-0000-000000000001', 6, 'Bậc 6: Cho kWh từ 401 trở lên', 400, NULL, 3151.00)
;

-- 7. SEED CẤU HÌNH AI ENGINE & PROMPTS MẪU
INSERT INTO ai_models (model_code, provider, model_name, task_category)
VALUES
    ('gpt-4o-mini', 'OPENAI', 'OpenAI GPT-4o Mini', 'CHATBOT_RAG_CONVERSATION'),
    ('gemini-1.5-flash', 'GOOGLE_GEMINI', 'Google Gemini 1.5 Flash', 'TICKET_TRIAGE_CLASSIFICATION'),
    ('trocr-vietnamese-meters', 'LOCAL_YOLO_TROCR', 'TrOCR Local Meter OCR', 'METER_OCR'),
    ('yolov8-license-plate', 'LOCAL_YOLO_TROCR', 'YOLOv8 ANPR Barrier', 'ANPR_LICENSE_PLATE')
;

INSERT INTO ai_prompts (prompt_code, task_type, system_prompt_text)
VALUES
    ('PROMPT_RESIDENT_RAG_ASSISTANT', 'CHATBOT_RAG_CONVERSATION', 'Bạn là trợ lý ảo AI thông minh của Ban Quản Lý Chung Cư Smart Building. Hãy trả lời cư dân một cách lịch sự, chính xác dựa trên tài liệu nội quy, biểu phí và thông báo của tòa nhà.'),
    ('PROMPT_TICKET_TRIAGE', 'TICKET_TRIAGE_CLASSIFICATION', 'Hãy phân tích nội dung báo cáo sự cố từ cư dân, xác định loại sự cố (Điện, Nước, Thang máy, Tiếng ồn), mức độ khẩn cấp (LOW, MEDIUM, HIGH, CRITICAL) và tóm tắt nguyên nhân dự kiến.')
;


/* ================= T-SQL FUNCTIONS & TRIGGERS ================= */
GO
CREATE OR ALTER FUNCTION dbo.fn_check_amenity_conflict
(
    @p_amenity_id UNIQUEIDENTIFIER,
    @p_booking_date DATE,
    @p_start_time TIME,
    @p_end_time TIME,
    @p_exclude_booking_id UNIQUEIDENTIFIER = NULL
)
RETURNS BIT
AS
BEGIN
    DECLARE @result BIT = 0;
    IF EXISTS (
        SELECT 1 FROM amenity_bookings
        WHERE amenity_id = @p_amenity_id AND booking_date = @p_booking_date
          AND status IN ('PENDING','APPROVED','CHECKED_IN')
          AND (@p_exclude_booking_id IS NULL OR id <> @p_exclude_booking_id)
          AND start_time < @p_end_time AND end_time > @p_start_time
    ) SET @result = 1;
    RETURN @result;
END;
GO

CREATE OR ALTER TRIGGER dbo.trg_prevent_booking_overlap
ON amenity_bookings
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (
        SELECT 1 FROM inserted i
        WHERE i.status IN ('PENDING','APPROVED')
          AND EXISTS (
            SELECT 1 FROM amenity_bookings b
            WHERE b.amenity_id=i.amenity_id AND b.booking_date=i.booking_date
              AND b.id<>i.id AND b.status IN ('PENDING','APPROVED','CHECKED_IN')
              AND b.start_time < i.end_time AND b.end_time > i.start_time
          )
    )
    BEGIN
        ROLLBACK TRANSACTION;
        THROW 51001, N'Khung giờ này tại tiện ích đã có người đặt trước. Vui lòng chọn khung giờ khác!', 1;
    END
END;
GO

CREATE OR ALTER TRIGGER dbo.trg_detect_meter_anomaly
ON meter_readings
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    ;WITH A AS (
      SELECT i.id, i.meter_id, i.apartment_id, i.billing_cycle, i.consumed_units,
             AVG(CAST(p.consumed_units AS DECIMAL(18,4))) AS avg_usage
      FROM inserted i
      OUTER APPLY (
        SELECT TOP (3) mr.consumed_units
        FROM meter_readings mr
        WHERE mr.meter_id=i.meter_id AND mr.billing_cycle<i.billing_cycle
        ORDER BY mr.billing_cycle DESC
      ) p
      GROUP BY i.id,i.meter_id,i.apartment_id,i.billing_cycle,i.consumed_units
    )
    UPDATE mr SET is_abnormal_consumption=1,
      abnormal_reason=N'AI phát hiện tiêu thụ tăng đột biến ' +
        CONVERT(NVARCHAR(30), CONVERT(DECIMAL(10,1),((a.consumed_units-a.avg_usage)/a.avg_usage)*100))+
        N'% so với trung bình quá khứ'
    FROM meter_readings mr JOIN A a ON a.id=mr.id
    WHERE a.avg_usage IS NOT NULL AND a.avg_usage>5
      AND ((a.consumed_units-a.avg_usage)/a.avg_usage)*100 >= 60;
END;
GO
