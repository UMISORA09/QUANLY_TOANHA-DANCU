# Quy tắc Kiến trúc Modular / Modular Architecture Rules

## Cấu trúc Module

Dự án sử dụng kiến trúc modular với 3 module trong `app/Modules/`:

| Module | Phụ trách | Prefix Route |
|--------|-----------|--------------|
| **Billing** | Hòa | `api/billing` |
| **ResidentService** | Nguyên | `api/resident-service` |
| **Reception** | Tín | `api/reception` |

## Quy tắc BẮT BUỘC

### 1. Tách biệt Module
- Mỗi module có cấu trúc riêng: Models, Controllers, Services, Events, Listeners, Migrations, Routes.
- **TUYỆT ĐỐI KHÔNG** query trực tiếp Model của module khác.

### 2. Cross-Module Data Communication
- Nếu Module A cần data từ Module B → Module B phải cung cấp qua class trong `Services/`.
- Module A gọi Service đó thông qua **Dependency Injection**.
- Ví dụ: Billing cần data Resident → Inject `ReceptionQueryService`.

### 3. Cross-Module Action Triggering
- Dùng kiến trúc **Event & Listener**.
- Module A chỉ được `dispatch()` một Event.
- Module B tạo Listener (`implements ShouldQueue`) để hứng Event và xử lý ngầm.
- Ví dụ: Reception nhận bưu phẩm → dispatch `ParcelReceived` → ResidentService listener gửi thông báo.

### 4. Database
- Mỗi module tự tạo file **Migration riêng** trong `app/Modules/<ModuleName>/database/migrations/`.
- Không được tạo migration ở `database/migrations/` cho logic module.

### 5. Naming Convention
- ServiceProvider: `<ModuleName>ServiceProvider`
- QueryService: `<ModuleName>QueryService`
- Event: Dạng past tense (`InvoiceCreated`, `ParcelReceived`)
- Route prefix: `api/<module-kebab-case>`
