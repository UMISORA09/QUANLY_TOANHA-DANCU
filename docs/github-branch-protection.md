# GitHub Branch Protection & Collaboration Rules

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**  
Tài liệu hướng dẫn thiết lập luật bảo vệ nhánh và quy trình làm việc nhóm trên GitHub.

---

## 1. Mô Hình Phân Nhánh (Branch Strategy)

Hệ thống áp dụng mô hình GitFlow cải tiến phù hợp cho nhóm lập trình viên đa vai trò:

```
feature/*  fix/*  hotfix/*
    │        │       │
    └────┬───┘       │
         ▼           │
    Pull Request     │
         ▼           │
     CI Checks       │
         ▼           │
     develop ────────┘
         │
    Staging Deploy & Verification
         │
    Pull Request
         │
       main (Production)
```

- `main`: Nhánh production ổn định tuyệt đối. Chỉ chứa mã nguồn đã qua kiểm thử và deploy lên Production.
- `develop`: Nhánh tích hợp chính của nhóm. Mọi tính năng sau khi hoàn thành sẽ merge vào đây để deploy Staging.
- `feature/<name>`: Nhánh phát triển tính năng mới (ví dụ: `DangNguyen/amenity-management`, `QuocTin/rbac-auth`, `xuanhoa/login_signup`).
- `fix/<issue>` hoặc `hotfix/<issue>`: Nhánh sửa lỗi khẩn cấp.

---

## 2. Quy Tắc Bảo Vệ Nhánh (Branch Protection Rules)

Do Agent không có quyền truy cập trực tiếp vào phần cài đặt quyền hạn GitHub Repository Settings, Quản trị viên dự án (Admin Repo) vui lòng cấu hình trên GitHub Web UI theo các bước sau:

### 2.1. Cấu hình bảo vệ cho nhánh `main`
Truy cập: **GitHub Repo -> Settings -> Branches -> Add branch protection rule**
- **Branch name pattern**: `main`
- [x] **Require a pull request before merging**:
  - [x] **Require approvals**: Tối thiểu `1` phê duyệt từ thành viên nhóm hoặc trưởng nhóm.
  - [x] **Dismiss stale pull request approvals when new commits are pushed**: Hủy phê duyệt cũ khi có commit mới.
- [x] **Require status checks to pass before merging**:
  - [x] **Require branches to be up to date before merging**: Bắt buộc nhánh phải rebase/merge mới nhất trước khi gộp.
  - **Status checks required**: Chọn các job trong `CI - Continuous Integration`:
    - `PHP Lint (Pint)`
    - `Backend Test & Migrations (PHPUnit)`
    - `Frontend Build (Vite & React)`
    - `Security Vulnerability Scan`
    - `Docker Build & Smoke Test`
    - `CI Pipeline Status Check`
- [x] **Require conversation resolution before merging**: Bắt buộc xử lý hết toàn bộ comment code review.
- [x] **Do not allow bypassing the above settings**: Áp dụng quy tắc cho cả repository administrators.
- [ ] **Allow force pushes**: **TẮT** (Tuyệt đối không cho phép force push).
- [ ] **Allow deletions**: **TẮT** (Không cho phép xóa nhánh `main`).

---

### 2.2. Cấu hình bảo vệ cho nhánh `develop`
- **Branch name pattern**: `develop`
- [x] **Require a pull request before merging** (ít nhất 1 review).
- [x] **Require status checks to pass before merging** (`CI Pipeline Status Check`).
- [ ] **Allow force pushes**: **TẮT**.

---

## 3. Cấu hình CODEOWNERS (Tùy chọn)

Nếu muốn phân quyền tự động gán người đánh giá code theo module, tạo tệp `.github/CODEOWNERS`:
```
# Cấu hình người phụ trách chính cho từng khu vực mã nguồn
*                   @UMISORA09

# Backend & DevOps Core
/app/Services/Cicd/ @UMISORA09
/.github/workflows/ @UMISORA09
/docker/            @UMISORA09
/scripts/           @UMISORA09

# Authentication & RBAC
/app/Http/Controllers/AuthController.php @QuocTin

# Quản lý tiện ích & Căn hộ
/app/Http/Controllers/AmenityController.php @DangNguyen

# Giao diện Frontend Cư Dân & Lễ Tân
/resources/js/Pages/ @xuanhoa
```
