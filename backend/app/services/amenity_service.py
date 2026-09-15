import json
import uuid
from datetime import datetime, date, time
from decimal import Decimal
from typing import Optional, List, Tuple
from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from ..repositories.amenity_repo import AmenityRepository
from ..core.audit import AuditService
from ..models.amenity import (
    AmenityCategory,
    Amenity,
    AmenityTimeSlot,
    AmenityBlackout,
)
from ..schemas.amenity import (
    CategoryCreate,
    CategoryUpdate,
    CategoryResponse,
    AmenityCreate,
    AmenityUpdate,
    AmenityResponse,
    TimeSlotCreate,
    TimeSlotUpdate,
    BlackoutCreate,
    BlackoutUpdate,
)

def parse_time_str(val: str) -> time:
    val = val.strip()
    parts = val.split(":")
    if len(parts) == 2:
        return time(hour=int(parts[0]), minute=int(parts[1]), second=0)
    elif len(parts) >= 3:
        sec = int(parts[2].split(".")[0])
        return time(hour=int(parts[0]), minute=int(parts[1]), second=sec)
    raise ValueError(f"Không thể phân tích định dạng giờ: {val}")

class AmenityService:
    # ================= CATEGORY SERVICE =================
    @staticmethod
    def list_categories(db: Session) -> List[CategoryResponse]:
        rows = AmenityRepository.get_categories(db)
        results = []
        for cat, count in rows:
            results.append(
                CategoryResponse(
                    id=cat.id,
                    category_name=cat.category_name,
                    category_code=cat.category_code,
                    icon_name=cat.icon_name,
                    description=cat.description,
                    created_at=cat.created_at,
                    amenities_count=count,
                )
            )
        return results

    @staticmethod
    def get_category(db: Session, category_id: uuid.UUID) -> CategoryResponse:
        cat = AmenityRepository.get_category_by_id(db, category_id)
        if not cat:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy danh mục tiện ích.")
        count = AmenityRepository.count_amenities_in_category(db, category_id)
        return CategoryResponse(
            id=cat.id,
            category_name=cat.category_name,
            category_code=cat.category_code,
            icon_name=cat.icon_name,
            description=cat.description,
            created_at=cat.created_at,
            amenities_count=count,
        )

    @staticmethod
    def create_category(
        db: Session,
        data: CategoryCreate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> CategoryResponse:
        existing = AmenityRepository.get_category_by_code(db, data.category_code)
        if existing:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Mã danh mục '{data.category_code}' đã tồn tại trong hệ thống.",
            )

        cat = AmenityCategory(
            id=uuid.uuid4(),
            category_name=data.category_name.strip(),
            category_code=data.category_code.strip().upper(),
            icon_name=data.icon_name,
            description=data.description,
            created_at=datetime.now(),
        )
        AmenityRepository.create_category(db, cat)

        AuditService.log_change(
            db=db,
            table_name="amenity_categories",
            record_id=cat.id,
            action="INSERT",
            performed_by_user_id=user_id,
            old_data=None,
            new_data={
                "category_name": cat.category_name,
                "category_code": cat.category_code,
                "icon_name": cat.icon_name,
                "description": cat.description,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(cat)
        return CategoryResponse(
            id=cat.id,
            category_name=cat.category_name,
            category_code=cat.category_code,
            icon_name=cat.icon_name,
            description=cat.description,
            created_at=cat.created_at,
            amenities_count=0,
        )

    @staticmethod
    def update_category(
        db: Session,
        category_id: uuid.UUID,
        data: CategoryUpdate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> CategoryResponse:
        cat = AmenityRepository.get_category_by_id(db, category_id)
        if not cat:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy danh mục tiện ích.")

        if cat.category_code.lower() != data.category_code.lower():
            dup = AmenityRepository.get_category_by_code(db, data.category_code)
            if dup and dup.id != cat.id:
                raise HTTPException(
                    status_code=status.HTTP_409_CONFLICT,
                    detail=f"Mã danh mục '{data.category_code}' đã tồn tại trong hệ thống.",
                )

        old_snapshot = {
            "category_name": cat.category_name,
            "category_code": cat.category_code,
            "icon_name": cat.icon_name,
            "description": cat.description,
        }

        changed = []
        if cat.category_name != data.category_name.strip():
            cat.category_name = data.category_name.strip()
            changed.append("category_name")
        if cat.category_code != data.category_code.strip().upper():
            cat.category_code = data.category_code.strip().upper()
            changed.append("category_code")
        if cat.icon_name != data.icon_name:
            cat.icon_name = data.icon_name
            changed.append("icon_name")
        if cat.description != data.description:
            cat.description = data.description
            changed.append("description")

        if changed:
            AuditService.log_change(
                db=db,
                table_name="amenity_categories",
                record_id=cat.id,
                action="UPDATE",
                performed_by_user_id=user_id,
                old_data=old_snapshot,
                new_data={
                    "category_name": cat.category_name,
                    "category_code": cat.category_code,
                    "icon_name": cat.icon_name,
                    "description": cat.description,
                },
                changed_fields=changed,
                client_ip=client_ip,
                user_agent=user_agent,
            )
            db.commit()
            db.refresh(cat)

        count = AmenityRepository.count_amenities_in_category(db, category_id)
        return CategoryResponse(
            id=cat.id,
            category_name=cat.category_name,
            category_code=cat.category_code,
            icon_name=cat.icon_name,
            description=cat.description,
            created_at=cat.created_at,
            amenities_count=count,
        )

    @staticmethod
    def delete_category(
        db: Session,
        category_id: uuid.UUID,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> None:
        cat = AmenityRepository.get_category_by_id(db, category_id)
        if not cat:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy danh mục tiện ích.")

        amenity_count = AmenityRepository.count_amenities_in_category(db, category_id)
        if amenity_count > 0:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Không thể xóa danh mục '{cat.category_name}' vì đang có {amenity_count} tiện ích trực thuộc.",
            )

        old_snapshot = {
            "category_name": cat.category_name,
            "category_code": cat.category_code,
        }
        AmenityRepository.delete_category(db, cat)
        AuditService.log_change(
            db=db,
            table_name="amenity_categories",
            record_id=category_id,
            action="DELETE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data=None,
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()

    # ================= AMENITY SERVICE =================
    @staticmethod
    def _to_amenity_response(amenity: Amenity) -> AmenityResponse:
        gallery = []
        if amenity.gallery_images:
            try:
                gallery = json.loads(amenity.gallery_images)
            except Exception:
                gallery = []

        active_slots = [s for s in (amenity.time_slots or []) if s.is_active]
        max_bookings = max([s.max_bookings for s in (amenity.time_slots or [])], default=1)

        return AmenityResponse(
            id=amenity.id,
            category_id=amenity.category_id,
            block_id=amenity.block_id,
            amenity_name=amenity.amenity_name,
            amenity_code=amenity.amenity_code,
            location_detail=amenity.location_detail,
            max_capacity_per_slot=amenity.max_capacity_per_slot,
            hourly_rate=amenity.hourly_rate,
            security_deposit_required=amenity.security_deposit_required,
            advance_booking_days_limit=amenity.advance_booking_days_limit,
            min_cancel_hours_before=amenity.min_cancel_hours_before,
            requires_admin_approval=amenity.requires_admin_approval,
            rules_and_regulations=amenity.rules_and_regulations,
            cover_image_url=amenity.cover_image_url,
            gallery_images=gallery,
            is_active=amenity.is_active,
            version=amenity.version or 1,
            created_at=amenity.created_at,
            updated_at=amenity.updated_at,
            deleted_at=amenity.deleted_at,
            category_name=amenity.category.category_name if amenity.category else None,
            category_code=amenity.category.category_code if amenity.category else None,
            block_name=amenity.block.block_name if amenity.block else None,
            block_code=amenity.block.block_code if amenity.block else None,
            time_slots_count=len(amenity.time_slots or []),
            active_time_slots_count=len(active_slots),
            max_bookings_per_slot=max_bookings,
        )

    @staticmethod
    def list_amenities(
        db: Session,
        search: Optional[str] = None,
        category_id: Optional[uuid.UUID] = None,
        block_id: Optional[uuid.UUID] = None,
        is_active: Optional[bool] = None,
        page: int = 1,
        limit: int = 20,
        sort: str = "created_at_desc",
    ) -> Tuple[List[AmenityResponse], int]:
        items, total = AmenityRepository.get_amenities_paged(
            db=db,
            search=search,
            category_id=category_id,
            block_id=block_id,
            is_active=is_active,
            page=page,
            limit=limit,
            sort=sort,
        )
        responses = [AmenityService._to_amenity_response(item) for item in items]
        return responses, total

    @staticmethod
    def get_amenity(db: Session, amenity_id: uuid.UUID) -> AmenityResponse:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")
        return AmenityService._to_amenity_response(amenity)

    @staticmethod
    def create_amenity(
        db: Session,
        data: AmenityCreate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityResponse:
        cat = AmenityRepository.get_category_by_id(db, data.category_id)
        if not cat:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Danh mục được chọn không tồn tại.")

        if data.block_id:
            blk = AmenityRepository.get_block_by_id(db, data.block_id)
            if not blk:
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tòa nhà được chọn không tồn tại.")

        dup = AmenityRepository.get_amenity_by_code(db, data.amenity_code)
        if dup:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Mã tiện ích '{data.amenity_code}' đã tồn tại trong hệ thống.",
            )

        gallery_json = json.dumps(data.gallery_images or [], ensure_ascii=False)

        amenity = Amenity(
            id=uuid.uuid4(),
            category_id=data.category_id,
            block_id=data.block_id,
            amenity_name=data.amenity_name.strip(),
            amenity_code=data.amenity_code.strip().upper(),
            location_detail=data.location_detail.strip(),
            max_capacity_per_slot=data.max_capacity_per_slot,
            hourly_rate=data.hourly_rate,
            security_deposit_required=data.security_deposit_required,
            advance_booking_days_limit=data.advance_booking_days_limit,
            min_cancel_hours_before=data.min_cancel_hours_before,
            requires_admin_approval=data.requires_admin_approval,
            rules_and_regulations=data.rules_and_regulations,
            cover_image_url=data.cover_image_url,
            gallery_images=gallery_json,
            is_active=data.is_active,
            version=1,
            created_at=datetime.now(),
            updated_at=datetime.now(),
        )
        AmenityRepository.create_amenity(db, amenity)

        AuditService.log_change(
            db=db,
            table_name="amenities",
            record_id=amenity.id,
            action="INSERT",
            performed_by_user_id=user_id,
            old_data=None,
            new_data={
                "amenity_name": amenity.amenity_name,
                "amenity_code": amenity.amenity_code,
                "category_id": str(amenity.category_id),
                "max_capacity_per_slot": amenity.max_capacity_per_slot,
                "hourly_rate": float(amenity.hourly_rate),
                "security_deposit_required": float(amenity.security_deposit_required),
                "is_active": amenity.is_active,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(amenity)
        return AmenityService._to_amenity_response(amenity)

    @staticmethod
    def update_amenity(
        db: Session,
        amenity_id: uuid.UUID,
        data: AmenityUpdate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityResponse:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")

        # --- OPTIMISTIC CONCURRENCY CONTROL (OCC) CHECK ---
        current_version = amenity.version or 1
        if current_version != data.version:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Dữ liệu tiện ích '{amenity.amenity_name}' đã được cập nhật bởi người dùng khác (phiên bản máy chủ: {current_version}, phiên bản gửi lên: {data.version}). Vui lòng tải lại trang để xem thông tin mới nhất trước khi chỉnh sửa.",
            )

        if amenity.amenity_code.lower() != data.amenity_code.lower():
            dup = AmenityRepository.get_amenity_by_code(db, data.amenity_code)
            if dup and dup.id != amenity.id:
                raise HTTPException(
                    status_code=status.HTTP_409_CONFLICT,
                    detail=f"Mã tiện ích '{data.amenity_code}' đã tồn tại trong hệ thống.",
                )

        if amenity.category_id != data.category_id:
            cat = AmenityRepository.get_category_by_id(db, data.category_id)
            if not cat:
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Danh mục được chọn không tồn tại.")

        if data.block_id and amenity.block_id != data.block_id:
            blk = AmenityRepository.get_block_by_id(db, data.block_id)
            if not blk:
                raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Tòa nhà được chọn không tồn tại.")

        old_snapshot = {
            "amenity_name": amenity.amenity_name,
            "amenity_code": amenity.amenity_code,
            "version": current_version,
            "category_id": str(amenity.category_id),
            "block_id": str(amenity.block_id) if amenity.block_id else None,
            "location_detail": amenity.location_detail,
            "max_capacity_per_slot": amenity.max_capacity_per_slot,
            "hourly_rate": float(amenity.hourly_rate),
            "security_deposit_required": float(amenity.security_deposit_required),
            "advance_booking_days_limit": amenity.advance_booking_days_limit,
            "min_cancel_hours_before": amenity.min_cancel_hours_before,
            "requires_admin_approval": amenity.requires_admin_approval,
            "is_active": amenity.is_active,
        }

        # Apply updates
        changed = []
        if amenity.category_id != data.category_id:
            amenity.category_id = data.category_id
            changed.append("category_id")
        if amenity.block_id != data.block_id:
            amenity.block_id = data.block_id
            changed.append("block_id")
        if amenity.amenity_name != data.amenity_name.strip():
            amenity.amenity_name = data.amenity_name.strip()
            changed.append("amenity_name")
        if amenity.amenity_code != data.amenity_code.strip().upper():
            amenity.amenity_code = data.amenity_code.strip().upper()
            changed.append("amenity_code")
        if amenity.location_detail != data.location_detail.strip():
            amenity.location_detail = data.location_detail.strip()
            changed.append("location_detail")
        if amenity.max_capacity_per_slot != data.max_capacity_per_slot:
            amenity.max_capacity_per_slot = data.max_capacity_per_slot
            changed.append("max_capacity_per_slot")
        if amenity.hourly_rate != data.hourly_rate:
            amenity.hourly_rate = data.hourly_rate
            changed.append("hourly_rate")
        if amenity.security_deposit_required != data.security_deposit_required:
            amenity.security_deposit_required = data.security_deposit_required
            changed.append("security_deposit_required")
        if amenity.advance_booking_days_limit != data.advance_booking_days_limit:
            amenity.advance_booking_days_limit = data.advance_booking_days_limit
            changed.append("advance_booking_days_limit")
        if amenity.min_cancel_hours_before != data.min_cancel_hours_before:
            amenity.min_cancel_hours_before = data.min_cancel_hours_before
            changed.append("min_cancel_hours_before")
        if amenity.requires_admin_approval != data.requires_admin_approval:
            amenity.requires_admin_approval = data.requires_admin_approval
            changed.append("requires_admin_approval")
        if amenity.rules_and_regulations != data.rules_and_regulations:
            amenity.rules_and_regulations = data.rules_and_regulations
            changed.append("rules_and_regulations")
        if amenity.cover_image_url != data.cover_image_url:
            amenity.cover_image_url = data.cover_image_url
            changed.append("cover_image_url")
        new_gallery = json.dumps(data.gallery_images or [], ensure_ascii=False)
        if amenity.gallery_images != new_gallery:
            amenity.gallery_images = new_gallery
            changed.append("gallery_images")
        if amenity.is_active != data.is_active:
            amenity.is_active = data.is_active
            changed.append("is_active")

        # OCC Version increment and updated_at
        amenity.version = current_version + 1
        amenity.updated_at = datetime.now()
        changed.append("version")

        AuditService.log_change(
            db=db,
            table_name="amenities",
            record_id=amenity.id,
            action="UPDATE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data={
                "amenity_name": amenity.amenity_name,
                "amenity_code": amenity.amenity_code,
                "version": amenity.version,
                "max_capacity_per_slot": amenity.max_capacity_per_slot,
                "hourly_rate": float(amenity.hourly_rate),
                "security_deposit_required": float(amenity.security_deposit_required),
                "is_active": amenity.is_active,
            },
            changed_fields=changed,
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(amenity)

        return AmenityService._to_amenity_response(amenity)

    @staticmethod
    def patch_amenity_status(
        db: Session,
        amenity_id: uuid.UUID,
        is_active: bool,
        version: Optional[int] = None,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityResponse:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")

        current_version = amenity.version or 1
        if version is not None and current_version != version:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Dữ liệu tiện ích '{amenity.amenity_name}' đã được cập nhật bởi người dùng khác (phiên bản máy chủ: {current_version}, phiên bản gửi lên: {version}). Vui lòng tải lại trang trước khi thao tác.",
            )

        old_status = amenity.is_active
        amenity.is_active = is_active
        amenity.version = current_version + 1
        amenity.updated_at = datetime.now()

        AuditService.log_change(
            db=db,
            table_name="amenities",
            record_id=amenity.id,
            action="STATUS_CHANGE",
            performed_by_user_id=user_id,
            old_data={"is_active": old_status, "version": current_version},
            new_data={"is_active": is_active, "version": amenity.version},
            changed_fields=["is_active", "version"],
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(amenity)
        return AmenityService._to_amenity_response(amenity)

    @staticmethod
    def delete_amenity(
        db: Session,
        amenity_id: uuid.UUID,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> None:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")

        # Always soft-delete to protect foreign key relationships and audit logs
        amenity.deleted_at = datetime.now()
        amenity.is_active = False

        AuditService.log_change(
            db=db,
            table_name="amenities",
            record_id=amenity.id,
            action="DELETE",
            performed_by_user_id=user_id,
            old_data={"amenity_name": amenity.amenity_name, "amenity_code": amenity.amenity_code},
            new_data={"deleted_at": str(amenity.deleted_at)},
            changed_fields=["deleted_at", "is_active"],
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()

    # ================= TIME SLOTS =================
    @staticmethod
    def list_time_slots(db: Session, amenity_id: uuid.UUID) -> List[AmenityTimeSlot]:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")
        return AmenityRepository.get_time_slots(db, amenity_id)

    @staticmethod
    def create_time_slot(
        db: Session,
        amenity_id: uuid.UUID,
        data: TimeSlotCreate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityTimeSlot:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")

        start_t = parse_time_str(data.slot_start_time)
        end_t = parse_time_str(data.slot_end_time)

        # Overlap check
        is_overlapping = AmenityRepository.check_time_slot_overlap(
            db=db,
            amenity_id=amenity_id,
            day_of_week=data.day_of_week,
            start_time=start_t,
            end_time=end_t,
        )
        if is_overlapping:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Khung giờ ({data.slot_start_time} - {data.slot_end_time}) bị trùng lặp với một khung giờ đã được cấu hình trước đó trong cùng ngày.",
            )

        slot = AmenityTimeSlot(
            id=uuid.uuid4(),
            amenity_id=amenity_id,
            day_of_week=data.day_of_week,
            slot_start_time=start_t,
            slot_end_time=end_t,
            slot_label=data.slot_label,
            max_bookings=data.max_bookings,
            is_active=data.is_active,
            created_at=datetime.now(),
        )
        AmenityRepository.create_time_slot(db, slot)

        AuditService.log_change(
            db=db,
            table_name="amenity_time_slots",
            record_id=slot.id,
            action="INSERT",
            performed_by_user_id=user_id,
            old_data=None,
            new_data={
                "amenity_id": str(amenity_id),
                "day_of_week": slot.day_of_week,
                "slot_start_time": str(slot.slot_start_time),
                "slot_end_time": str(slot.slot_end_time),
                "max_bookings": slot.max_bookings,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(slot)
        return slot

    @staticmethod
    def update_time_slot(
        db: Session,
        amenity_id: uuid.UUID,
        slot_id: uuid.UUID,
        data: TimeSlotUpdate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityTimeSlot:
        slot = AmenityRepository.get_time_slot_by_id(db, slot_id)
        if not slot or slot.amenity_id != amenity_id:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy khung giờ tiện ích.")

        start_t = parse_time_str(data.slot_start_time)
        end_t = parse_time_str(data.slot_end_time)

        # Overlap check
        is_overlapping = AmenityRepository.check_time_slot_overlap(
            db=db,
            amenity_id=amenity_id,
            day_of_week=data.day_of_week,
            start_time=start_t,
            end_time=end_t,
            exclude_slot_id=slot_id,
        )
        if is_overlapping:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Khung giờ ({data.slot_start_time} - {data.slot_end_time}) bị trùng lặp với một khung giờ khác trong cùng ngày.",
            )

        old_snapshot = {
            "day_of_week": slot.day_of_week,
            "slot_start_time": str(slot.slot_start_time),
            "slot_end_time": str(slot.slot_end_time),
            "max_bookings": slot.max_bookings,
            "is_active": slot.is_active,
        }

        slot.day_of_week = data.day_of_week
        slot.slot_start_time = start_t
        slot.slot_end_time = end_t
        slot.slot_label = data.slot_label
        slot.max_bookings = data.max_bookings
        slot.is_active = data.is_active

        AuditService.log_change(
            db=db,
            table_name="amenity_time_slots",
            record_id=slot.id,
            action="UPDATE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data={
                "day_of_week": slot.day_of_week,
                "slot_start_time": str(slot.slot_start_time),
                "slot_end_time": str(slot.slot_end_time),
                "max_bookings": slot.max_bookings,
                "is_active": slot.is_active,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(slot)
        return slot

    @staticmethod
    def patch_time_slot_status(
        db: Session,
        amenity_id: uuid.UUID,
        slot_id: uuid.UUID,
        is_active: bool,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityTimeSlot:
        slot = AmenityRepository.get_time_slot_by_id(db, slot_id)
        if not slot or slot.amenity_id != amenity_id:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy khung giờ tiện ích.")

        old_status = slot.is_active
        slot.is_active = is_active

        AuditService.log_change(
            db=db,
            table_name="amenity_time_slots",
            record_id=slot.id,
            action="STATUS_CHANGE",
            performed_by_user_id=user_id,
            old_data={"is_active": old_status},
            new_data={"is_active": is_active},
            changed_fields=["is_active"],
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(slot)
        return slot

    @staticmethod
    def delete_time_slot(
        db: Session,
        amenity_id: uuid.UUID,
        slot_id: uuid.UUID,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> None:
        slot = AmenityRepository.get_time_slot_by_id(db, slot_id)
        if not slot or slot.amenity_id != amenity_id:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy khung giờ tiện ích.")

        old_snapshot = {
            "day_of_week": slot.day_of_week,
            "slot_start_time": str(slot.slot_start_time),
            "slot_end_time": str(slot.slot_end_time),
            "max_bookings": slot.max_bookings,
        }
        AmenityRepository.delete_time_slot(db, slot)
        AuditService.log_change(
            db=db,
            table_name="amenity_time_slots",
            record_id=slot_id,
            action="DELETE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data=None,
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()

    # ================= BLACKOUTS =================
    @staticmethod
    def list_blackouts(db: Session, amenity_id: uuid.UUID) -> List[AmenityBlackout]:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")
        return AmenityRepository.get_blackouts(db, amenity_id)

    @staticmethod
    def create_blackout(
        db: Session,
        amenity_id: uuid.UUID,
        data: BlackoutCreate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityBlackout:
        amenity = AmenityRepository.get_amenity_by_id(db, amenity_id)
        if not amenity:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy tiện ích.")

        start_t = parse_time_str(data.start_time) if data.start_time else None
        end_t = parse_time_str(data.end_time) if data.end_time else None

        blackout = AmenityBlackout(
            id=uuid.uuid4(),
            amenity_id=amenity_id,
            blackout_date=data.blackout_date,
            start_time=start_t,
            end_time=end_t,
            reason=data.reason.strip(),
            created_by=user_id,
            created_at=datetime.now(),
        )
        AmenityRepository.create_blackout(db, blackout)

        AuditService.log_change(
            db=db,
            table_name="amenity_blackouts",
            record_id=blackout.id,
            action="INSERT",
            performed_by_user_id=user_id,
            old_data=None,
            new_data={
                "amenity_id": str(amenity_id),
                "blackout_date": str(blackout.blackout_date),
                "start_time": str(blackout.start_time) if blackout.start_time else None,
                "end_time": str(blackout.end_time) if blackout.end_time else None,
                "reason": blackout.reason,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(blackout)
        return blackout

    @staticmethod
    def update_blackout(
        db: Session,
        amenity_id: uuid.UUID,
        blackout_id: uuid.UUID,
        data: BlackoutUpdate,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AmenityBlackout:
        blackout = AmenityRepository.get_blackout_by_id(db, blackout_id)
        if not blackout or blackout.amenity_id != amenity_id:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy ngày đóng cửa bảo trì.")

        start_t = parse_time_str(data.start_time) if data.start_time else None
        end_t = parse_time_str(data.end_time) if data.end_time else None

        old_snapshot = {
            "blackout_date": str(blackout.blackout_date),
            "start_time": str(blackout.start_time) if blackout.start_time else None,
            "end_time": str(blackout.end_time) if blackout.end_time else None,
            "reason": blackout.reason,
        }

        blackout.blackout_date = data.blackout_date
        blackout.start_time = start_t
        blackout.end_time = end_t
        blackout.reason = data.reason.strip()

        AuditService.log_change(
            db=db,
            table_name="amenity_blackouts",
            record_id=blackout.id,
            action="UPDATE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data={
                "blackout_date": str(blackout.blackout_date),
                "start_time": str(blackout.start_time) if blackout.start_time else None,
                "end_time": str(blackout.end_time) if blackout.end_time else None,
                "reason": blackout.reason,
            },
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
        db.refresh(blackout)
        return blackout

    @staticmethod
    def delete_blackout(
        db: Session,
        amenity_id: uuid.UUID,
        blackout_id: uuid.UUID,
        user_id: Optional[uuid.UUID] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> None:
        blackout = AmenityRepository.get_blackout_by_id(db, blackout_id)
        if not blackout or blackout.amenity_id != amenity_id:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Không tìm thấy ngày đóng cửa bảo trì.")

        old_snapshot = {
            "blackout_date": str(blackout.blackout_date),
            "reason": blackout.reason,
        }
        AmenityRepository.delete_blackout(db, blackout)
        AuditService.log_change(
            db=db,
            table_name="amenity_blackouts",
            record_id=blackout_id,
            action="DELETE",
            performed_by_user_id=user_id,
            old_data=old_snapshot,
            new_data=None,
            client_ip=client_ip,
            user_agent=user_agent,
        )
        db.commit()
