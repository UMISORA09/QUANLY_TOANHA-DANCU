import uuid
import pytest
from datetime import date, datetime
from sqlalchemy.orm import Session
from app.models.amenity import AmenityBooking, Amenity
from app.models.audit import AuditLog

class TestCategoryAPI:
    def test_unauthorized_access(self, client):
        # No token -> 401
        res = client.get("/api/v1/admin/amenity-categories")
        assert res.status_code == 401

    def test_resident_forbidden(self, client, resident_headers):
        # Resident token -> 403
        res = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": "Test Cat",
            "category_code": "TEST_CAT",
        }, headers=resident_headers)
        assert res.status_code == 403

    def test_create_category_success(self, client, admin_headers):
        code = f"CAT_{uuid.uuid4().hex[:6].upper()}"
        res = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Danh mục {code}",
            "category_code": code,
            "icon_name": "Sparkles",
            "description": "Mô tả danh mục thử nghiệm"
        }, headers=admin_headers)
        assert res.status_code == 201
        data = res.json()
        assert data["category_code"] == code
        assert "id" in data

    def test_duplicate_category_code(self, client, admin_headers):
        code = f"DUP_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Danh mục {code}",
            "category_code": code,
        }, headers=admin_headers)
        assert res1.status_code == 201

        # Duplicate
        res2 = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Danh mục trùng {code}",
            "category_code": code,
        }, headers=admin_headers)
        assert res2.status_code == 409

    def test_empty_category_name_validation(self, client, admin_headers):
        res = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": "   ",
            "category_code": "VALID_CODE",
        }, headers=admin_headers)
        assert res.status_code == 422

    def test_update_and_delete_category(self, client, admin_headers):
        code = f"DEL_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Danh mục xóa {code}",
            "category_code": code,
        }, headers=admin_headers)
        assert res1.status_code == 201
        cat_id = res1.json()["id"]

        # Update
        res_up = client.put(f"/api/v1/admin/amenity-categories/{cat_id}", json={
            "category_name": f"Danh mục cập nhật {code}",
            "category_code": code,
            "description": "Updated Description",
        }, headers=admin_headers)
        assert res_up.status_code == 200
        assert res_up.json()["description"] == "Updated Description"

        # Delete
        res_del = client.delete(f"/api/v1/admin/amenity-categories/{cat_id}", headers=admin_headers)
        assert res_del.status_code == 204

        # Verify not found
        res_get = client.get(f"/api/v1/admin/amenity-categories/{cat_id}", headers=admin_headers)
        assert res_get.status_code == 404


class TestAmenityAPI:
    @pytest.fixture
    def test_category_id(self, client, admin_headers):
        code = f"CAT_{uuid.uuid4().hex[:6].upper()}"
        res = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Category For Amenity {code}",
            "category_code": code,
        }, headers=admin_headers)
        return res.json()["id"]

    def test_create_amenity_success(self, client, admin_headers, test_category_id):
        code = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": f"Hồ bơi tràn bờ {code}",
            "amenity_code": code,
            "location_detail": "Tầng 5 - Tòa A",
            "max_capacity_per_slot": 25,
            "hourly_rate": 50000,
            "security_deposit_required": 100000,
            "advance_booking_days_limit": 14,
            "min_cancel_hours_before": 24,
            "requires_admin_approval": True,
            "rules_and_regulations": "Không mang thức ăn vào hồ bơi.",
            "is_active": True,
        }, headers=admin_headers)
        assert res.status_code == 201
        data = res.json()
        assert data["amenity_code"] == code
        assert data["max_capacity_per_slot"] == 25
        assert float(data["hourly_rate"]) == 50000.0

    def test_duplicate_amenity_code(self, client, admin_headers, test_category_id):
        code = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": "Tiện ích 1",
            "amenity_code": code,
            "location_detail": "Tầng 1",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        assert res1.status_code == 201

        res2 = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": "Tiện ích 2",
            "amenity_code": code,
            "location_detail": "Tầng 2",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        assert res2.status_code == 409

    def test_invalid_category_or_block(self, client, admin_headers):
        res = client.post("/api/v1/admin/amenities", json={
            "category_id": str(uuid.uuid4()),
            "amenity_name": "Tiện ích sai category",
            "amenity_code": f"ERR_{uuid.uuid4().hex[:6].upper()}",
            "location_detail": "Vị trí",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        assert res.status_code == 404

    def test_validation_errors(self, client, admin_headers, test_category_id):
        # Empty name
        res = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": "   ",
            "amenity_code": "VALID_CODE",
            "location_detail": "Location",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        assert res.status_code == 422

        # Capacity <= 0
        res = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": "Test Amenity",
            "amenity_code": "VALID_CODE",
            "location_detail": "Location",
            "max_capacity_per_slot": 0,
        }, headers=admin_headers)
        assert res.status_code == 422

        # Negative hourly rate
        res = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": "Test Amenity",
            "amenity_code": "VALID_CODE",
            "location_detail": "Location",
            "hourly_rate": -100,
        }, headers=admin_headers)
        assert res.status_code == 422

    def test_update_and_status_toggle_amenity(self, client, admin_headers, test_category_id):
        code = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": f"Gym {code}",
            "amenity_code": code,
            "location_detail": "Tầng 2",
            "max_capacity_per_slot": 15,
            "hourly_rate": 0,
        }, headers=admin_headers)
        assert res1.status_code == 201
        amenity_id = res1.json()["id"]

        # Update
        res_up = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": test_category_id,
            "amenity_name": f"Gym Cao Cấp {code}",
            "amenity_code": code,
            "location_detail": "Tầng 2 - Mở rộng",
            "max_capacity_per_slot": 30,
            "hourly_rate": 20000,
            "security_deposit_required": 0,
            "advance_booking_days_limit": 7,
            "min_cancel_hours_before": 6,
            "requires_admin_approval": False,
            "is_active": True,
            "version": 1,
        }, headers=admin_headers)
        assert res_up.status_code == 200
        assert res_up.json()["max_capacity_per_slot"] == 30
        assert res_up.json()["version"] == 2
        assert float(res_up.json()["hourly_rate"]) == 20000.0

        # Toggle inactive
        res_toggle = client.patch(f"/api/v1/admin/amenities/{amenity_id}/status", json={
            "is_active": False
        }, headers=admin_headers)
        assert res_toggle.status_code == 200
        assert res_toggle.json()["is_active"] is False

        # Toggle active back
        res_toggle_back = client.patch(f"/api/v1/admin/amenities/{amenity_id}/status", json={
            "is_active": True
        }, headers=admin_headers)
        assert res_toggle_back.status_code == 200
        assert res_toggle_back.json()["is_active"] is True

        # Delete (soft-delete)
        res_del = client.delete(f"/api/v1/admin/amenities/{amenity_id}", headers=admin_headers)
        assert res_del.status_code == 204

        # Fetch should return 404
        res_get = client.get(f"/api/v1/admin/amenities/{amenity_id}", headers=admin_headers)
        assert res_get.status_code == 404

    def test_amenity_optimistic_concurrency_control_success(self, client, admin_headers, test_category_id):
        code = f"OCC_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": f"Hồ bơi {code}",
            "amenity_code": code,
            "location_detail": "Tầng 5",
            "max_capacity_per_slot": 20,
        }, headers=admin_headers)
        assert res1.status_code == 201
        data1 = res1.json()
        amenity_id = data1["id"]
        assert data1["version"] == 1

        # First update with version = 1 -> version becomes 2
        res_up1 = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": test_category_id,
            "amenity_name": f"Hồ bơi VIP {code}",
            "amenity_code": code,
            "location_detail": "Tầng 5 - Mới",
            "max_capacity_per_slot": 30,
            "version": 1,
        }, headers=admin_headers)
        assert res_up1.status_code == 200
        data_up1 = res_up1.json()
        assert data_up1["version"] == 2
        assert data_up1["max_capacity_per_slot"] == 30

        # Second update with version = 2 -> version becomes 3
        res_up2 = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": test_category_id,
            "amenity_name": f"Hồ bơi Super VIP {code}",
            "amenity_code": code,
            "location_detail": "Tầng 5 - Cao cấp",
            "max_capacity_per_slot": 40,
            "version": 2,
        }, headers=admin_headers)
        assert res_up2.status_code == 200
        data_up2 = res_up2.json()
        assert data_up2["version"] == 3
        assert data_up2["max_capacity_per_slot"] == 40

    def test_amenity_optimistic_concurrency_control_conflict_409(self, client, admin_headers, test_category_id):
        code = f"CNF_{uuid.uuid4().hex[:6].upper()}"
        res1 = client.post("/api/v1/admin/amenities", json={
            "category_id": test_category_id,
            "amenity_name": f"Sân Tennis {code}",
            "amenity_code": code,
            "location_detail": "Khu thể thao",
            "max_capacity_per_slot": 4,
        }, headers=admin_headers)
        assert res1.status_code == 201
        amenity_id = res1.json()["id"]

        # Admin A updates with version = 1 -> version becomes 2
        res_admin_a = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": test_category_id,
            "amenity_name": f"Sân Tennis A {code}",
            "amenity_code": code,
            "location_detail": "Khu thể thao",
            "max_capacity_per_slot": 6,
            "version": 1,
        }, headers=admin_headers)
        assert res_admin_a.status_code == 200
        assert res_admin_a.json()["version"] == 2

        # Admin B attempts to update with outdated version = 1 -> 409 Conflict
        res_admin_b = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": test_category_id,
            "amenity_name": f"Sân Tennis B {code}",
            "amenity_code": code,
            "location_detail": "Khu thể thao",
            "max_capacity_per_slot": 8,
            "version": 1,
        }, headers=admin_headers)
        assert res_admin_b.status_code == 409
        err_data = res_admin_b.json()
        assert "cập nhật bởi người dùng khác" in err_data["detail"]

        # Verify DB still preserves Admin A's changes and version = 2
        res_get = client.get(f"/api/v1/admin/amenities/{amenity_id}", headers=admin_headers)
        assert res_get.status_code == 200
        assert res_get.json()["version"] == 2
        assert res_get.json()["max_capacity_per_slot"] == 6

    def test_delete_category_with_referenced_amenity_fails(self, client, admin_headers):
        # Create category
        code_cat = f"REF_{uuid.uuid4().hex[:6].upper()}"
        res_cat = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Category With Amenity {code_cat}",
            "category_code": code_cat,
        }, headers=admin_headers)
        cat_id = res_cat.json()["id"]

        # Create amenity referencing category
        code_amn = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res_amn = client.post("/api/v1/admin/amenities", json={
            "category_id": cat_id,
            "amenity_name": "Active Amenity",
            "amenity_code": code_amn,
            "location_detail": "Location",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        assert res_amn.status_code == 201

        # Delete category must return 409 Conflict
        res_del = client.delete(f"/api/v1/admin/amenity-categories/{cat_id}", headers=admin_headers)
        assert res_del.status_code == 409


class TestTimeSlotAPI:
    @pytest.fixture
    def test_amenity_id(self, client, admin_headers):
        code_cat = f"CAT_{uuid.uuid4().hex[:6].upper()}"
        res_cat = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Cat For Slot {code_cat}",
            "category_code": code_cat,
        }, headers=admin_headers)
        cat_id = res_cat.json()["id"]

        code_amn = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res_amn = client.post("/api/v1/admin/amenities", json={
            "category_id": cat_id,
            "amenity_name": f"Amenity For Slot {code_amn}",
            "amenity_code": code_amn,
            "location_detail": "Tầng 1",
            "max_capacity_per_slot": 20,
        }, headers=admin_headers)
        return res_amn.json()["id"]

    def test_create_time_slot_success(self, client, admin_headers, test_amenity_id):
        res = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 1,  # Thứ 2
            "slot_start_time": "09:00",
            "slot_end_time": "11:00",
            "slot_label": "Khung giờ sáng Thứ 2",
            "max_bookings": 2,
        }, headers=admin_headers)
        assert res.status_code == 201
        data = res.json()
        assert data["day_of_week"] == 1
        assert data["max_bookings"] == 2

    def test_adjacent_time_slots_allowed(self, client, admin_headers, test_amenity_id):
        # 14:00 - 16:00
        res1 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 2,
            "slot_start_time": "14:00",
            "slot_end_time": "16:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res1.status_code == 201

        # Adjacent: 16:00 - 18:00 (Allowed!)
        res2 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 2,
            "slot_start_time": "16:00",
            "slot_end_time": "18:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res2.status_code == 201

    def test_overlapping_time_slots_rejected(self, client, admin_headers, test_amenity_id):
        # 07:00 - 10:00
        res1 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 3,
            "slot_start_time": "07:00",
            "slot_end_time": "10:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res1.status_code == 201

        # Overlapping: 09:00 - 11:00 (Must be rejected with 409 Conflict)
        res2 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 3,
            "slot_start_time": "09:00",
            "slot_end_time": "11:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res2.status_code == 409

    def test_invalid_slot_times_validation(self, client, admin_headers, test_amenity_id):
        # start >= end
        res = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 4,
            "slot_start_time": "15:00",
            "slot_end_time": "14:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res.status_code == 422

        # max_bookings <= 0
        res2 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 4,
            "slot_start_time": "10:00",
            "slot_end_time": "12:00",
            "max_bookings": 0,
        }, headers=admin_headers)
        assert res2.status_code == 422

        # invalid day_of_week
        res3 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/time-slots", json={
            "day_of_week": 7,
            "slot_start_time": "10:00",
            "slot_end_time": "12:00",
            "max_bookings": 1,
        }, headers=admin_headers)
        assert res3.status_code == 422


class TestBlackoutAPI:
    @pytest.fixture
    def test_amenity_id(self, client, admin_headers):
        code_cat = f"CAT_{uuid.uuid4().hex[:6].upper()}"
        res_cat = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Cat For Blackout {code_cat}",
            "category_code": code_cat,
        }, headers=admin_headers)
        cat_id = res_cat.json()["id"]

        code_amn = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res_amn = client.post("/api/v1/admin/amenities", json={
            "category_id": cat_id,
            "amenity_name": f"Amenity For Blackout {code_amn}",
            "amenity_code": code_amn,
            "location_detail": "Tầng 1",
            "max_capacity_per_slot": 10,
        }, headers=admin_headers)
        return res_amn.json()["id"]

    def test_create_full_day_blackout(self, client, admin_headers, test_amenity_id):
        res = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/blackouts", json={
            "blackout_date": "2026-10-15",
            "start_time": None,
            "end_time": None,
            "reason": "Bảo trì định kỳ toàn ngày",
        }, headers=admin_headers)
        assert res.status_code == 201
        data = res.json()
        assert data["blackout_date"] == "2026-10-15"
        assert data["start_time"] is None
        assert data["end_time"] is None

    def test_create_partial_blackout(self, client, admin_headers, test_amenity_id):
        res = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/blackouts", json={
            "blackout_date": "2026-10-16",
            "start_time": "08:00",
            "end_time": "12:00",
            "reason": "Bảo dưỡng hệ thống điện",
        }, headers=admin_headers)
        assert res.status_code == 201
        data = res.json()
        assert data["start_time"] is not None

    def test_invalid_blackout_times(self, client, admin_headers, test_amenity_id):
        # Only start_time provided
        res1 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/blackouts", json={
            "blackout_date": "2026-10-17",
            "start_time": "08:00",
            "end_time": None,
            "reason": "Lỗi thiếu end_time",
        }, headers=admin_headers)
        assert res1.status_code == 422

        # start >= end
        res2 = client.post(f"/api/v1/admin/amenities/{test_amenity_id}/blackouts", json={
            "blackout_date": "2026-10-17",
            "start_time": "12:00",
            "end_time": "08:00",
            "reason": "Lỗi giờ ngược",
        }, headers=admin_headers)
        assert res2.status_code == 422


class TestBusinessRulesAndAudit:
    def test_configuration_effective_rule_preserves_historical_bookings(self, client, admin_headers, db_session):
        # Create category and amenity
        code_cat = f"CAT_{uuid.uuid4().hex[:6].upper()}"
        res_cat = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"BBQ {code_cat}",
            "category_code": code_cat,
        }, headers=admin_headers)
        cat_id = res_cat.json()["id"]

        code_amn = f"AMN_{uuid.uuid4().hex[:6].upper()}"
        res_amn = client.post("/api/v1/admin/amenities", json={
            "category_id": cat_id,
            "amenity_name": f"Vườn nướng BBQ {code_amn}",
            "amenity_code": code_amn,
            "location_detail": "Tầng thượng",
            "max_capacity_per_slot": 20,
            "hourly_rate": 100000,
            "security_deposit_required": 200000,
        }, headers=admin_headers)
        amenity_id = uuid.UUID(res_amn.json()["id"])

        # Insert a simulated historical booking directly in database
        from app.models.auth import User
        resident_user = db_session.query(User).filter(User.username == "cudan").first()
        res_user_id = resident_user.id if resident_user else uuid.UUID("F876300E-0098-49C8-932E-CF0CCE390789")
        apt_id = uuid.UUID("AA001204-0000-0000-0000-000000000001")
        booking = AmenityBooking(
            id=uuid.uuid4(),
            booking_code=f"BKG-{uuid.uuid4().hex[:8].upper()}",
            amenity_id=amenity_id,
            apartment_id=apt_id,
            resident_user_id=res_user_id,
            booking_date=date(2026, 9, 20),
            start_time=datetime.strptime("18:00", "%H:%M").time(),
            end_time=datetime.strptime("20:00", "%H:%M").time(),
            attendee_count=18,
            total_amount=200000.00,
            deposit_amount=200000.00,
            status="CONFIRMED",
            checkin_qr_code=f"QR-{uuid.uuid4().hex[:10].upper()}",
            created_at=datetime.now(),
            updated_at=datetime.now(),
        )
        db_session.add(booking)
        db_session.commit()

        # Admin modifies amenity configuration: reduces capacity to 10 and increases price to 150000
        res_up = client.put(f"/api/v1/admin/amenities/{amenity_id}", json={
            "category_id": cat_id,
            "amenity_name": f"Vườn nướng BBQ {code_amn}",
            "amenity_code": code_amn,
            "location_detail": "Tầng thượng",
            "max_capacity_per_slot": 10,
            "hourly_rate": 150000,
            "security_deposit_required": 300000,
            "is_active": True,
            "version": 1,
        }, headers=admin_headers)
        assert res_up.status_code == 200
        assert res_up.json()["version"] == 2

        # Verify historical booking is completely untouched
        db_session.expire_all()
        reloaded_booking = db_session.query(AmenityBooking).filter(AmenityBooking.id == booking.id).first()
        assert reloaded_booking is not None
        assert reloaded_booking.attendee_count == 18  # Did NOT change to 10
        assert float(reloaded_booking.total_amount) == 200000.00  # Did NOT recalculate to 300000
        assert float(reloaded_booking.deposit_amount) == 200000.00

        # Deactivate amenity
        res_deact = client.patch(f"/api/v1/admin/amenities/{amenity_id}/status", json={
            "is_active": False
        }, headers=admin_headers)
        assert res_deact.status_code == 200

        # Verify booking is still intact (NOT deleted or cancelled)
        db_session.expire_all()
        booking_after_deact = db_session.query(AmenityBooking).filter(AmenityBooking.id == booking.id).first()
        assert booking_after_deact is not None
        assert booking_after_deact.status == "CONFIRMED"

    def test_audit_logs_recorded(self, client, admin_headers, db_session):
        # Create category
        code_cat = f"AUD_{uuid.uuid4().hex[:6].upper()}"
        res_cat = client.post("/api/v1/admin/amenity-categories", json={
            "category_name": f"Audit Category {code_cat}",
            "category_code": code_cat,
        }, headers=admin_headers)
        assert res_cat.status_code == 201
        cat_id = uuid.UUID(res_cat.json()["id"])

        # Check audit_logs table
        audit_record = (
            db_session.query(AuditLog)
            .filter(AuditLog.table_name == "amenity_categories", AuditLog.record_id == cat_id)
            .first()
        )
        assert audit_record is not None
        assert audit_record.action == "INSERT"
        assert audit_record.new_data is not None
        assert code_cat in audit_record.new_data
