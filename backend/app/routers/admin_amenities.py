import uuid
from typing import Optional, List
from fastapi import APIRouter, Depends, HTTPException, Query, Request, status, Response
from sqlalchemy.orm import Session

from ..core.database import get_db
from ..core.security import require_permissions, get_current_user
from ..models.auth import User
from ..services.amenity_service import AmenityService
from ..schemas.amenity import (
    CategoryCreate,
    CategoryUpdate,
    CategoryResponse,
    AmenityCreate,
    AmenityUpdate,
    AmenityStatusPatch,
    AmenityResponse,
    AmenityListResponse,
    TimeSlotCreate,
    TimeSlotUpdate,
    TimeSlotStatusPatch,
    TimeSlotResponse,
    BlackoutCreate,
    BlackoutUpdate,
    BlackoutResponse,
)

router = APIRouter(prefix="/admin", tags=["Admin Amenity Management"])

# ==================== CATEGORIES ====================
@router.get(
    "/amenity-categories",
    response_model=List[CategoryResponse],
    summary="Danh sách danh mục tiện ích",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def list_categories(db: Session = Depends(get_db)):
    return AmenityService.list_categories(db)

@router.get(
    "/amenity-categories/{category_id}",
    response_model=CategoryResponse,
    summary="Chi tiết danh mục tiện ích",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def get_category(category_id: uuid.UUID, db: Session = Depends(get_db)):
    return AmenityService.get_category(db, category_id)

@router.post(
    "/amenity-categories",
    response_model=CategoryResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Tạo danh mục tiện ích mới",
)
def create_category(
    data: CategoryCreate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CREATE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    return AmenityService.create_category(
        db=db,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )

@router.put(
    "/amenity-categories/{category_id}",
    response_model=CategoryResponse,
    summary="Cập nhật danh mục tiện ích",
)
def update_category(
    category_id: uuid.UUID,
    data: CategoryUpdate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:UPDATE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    return AmenityService.update_category(
        db=db,
        category_id=category_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )

@router.delete(
    "/amenity-categories/{category_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Xóa danh mục tiện ích",
)
def delete_category(
    category_id: uuid.UUID,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:DELETE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    AmenityService.delete_category(
        db=db,
        category_id=category_id,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# ==================== AMENITIES ====================
@router.get(
    "/amenities",
    response_model=AmenityListResponse,
    summary="Danh sách tiện ích (phân trang, lọc, tìm kiếm)",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def list_amenities(
    search: Optional[str] = Query(None, description="Tìm theo tên, mã, vị trí"),
    category_id: Optional[uuid.UUID] = Query(None, description="Lọc theo danh mục"),
    block_id: Optional[uuid.UUID] = Query(None, description="Lọc theo tòa nhà"),
    is_active: Optional[bool] = Query(None, description="Lọc theo trạng thái"),
    page: int = Query(1, ge=1, description="Số trang"),
    limit: int = Query(20, ge=1, le=100, description="Số lượng mỗi trang"),
    sort: str = Query("created_at_desc", description="Thứ tự sắp xếp"),
    db: Session = Depends(get_db),
):
    items, total = AmenityService.list_amenities(
        db=db,
        search=search,
        category_id=category_id,
        block_id=block_id,
        is_active=is_active,
        page=page,
        limit=limit,
        sort=sort,
    )
    total_pages = (total + limit - 1) // limit if total > 0 else 1
    return AmenityListResponse(
        items=items,
        total=total,
        page=page,
        limit=limit,
        total_pages=total_pages,
    )

@router.get(
    "/amenities/{amenity_id}",
    response_model=AmenityResponse,
    summary="Chi tiết một tiện ích",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def get_amenity(amenity_id: uuid.UUID, db: Session = Depends(get_db)):
    return AmenityService.get_amenity(db, amenity_id)

@router.post(
    "/amenities",
    response_model=AmenityResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Tạo mới tiện ích",
)
def create_amenity(
    data: AmenityCreate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CREATE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    return AmenityService.create_amenity(
        db=db,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )

@router.put(
    "/amenities/{amenity_id}",
    response_model=AmenityResponse,
    summary="Cập nhật thông tin và cấu hình tiện ích",
)
def update_amenity(
    amenity_id: uuid.UUID,
    data: AmenityUpdate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:UPDATE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    return AmenityService.update_amenity(
        db=db,
        amenity_id=amenity_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )

@router.patch(
    "/amenities/{amenity_id}/status",
    response_model=AmenityResponse,
    summary="Bật / Tắt trạng thái hoạt động của tiện ích",
)
def patch_amenity_status(
    amenity_id: uuid.UUID,
    data: AmenityStatusPatch,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:UPDATE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    return AmenityService.patch_amenity_status(
        db=db,
        amenity_id=amenity_id,
        is_active=data.is_active,
        version=data.version,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )

@router.delete(
    "/amenities/{amenity_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Xóa tiện ích (Soft-delete an toàn)",
)
def delete_amenity(
    amenity_id: uuid.UUID,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:DELETE")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    AmenityService.delete_amenity(
        db=db,
        amenity_id=amenity_id,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# ==================== TIME SLOTS ====================
@router.get(
    "/amenities/{amenity_id}/time-slots",
    response_model=List[TimeSlotResponse],
    summary="Danh sách khung giờ của tiện ích",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def list_time_slots(amenity_id: uuid.UUID, db: Session = Depends(get_db)):
    slots = AmenityService.list_time_slots(db, amenity_id)
    return [
        TimeSlotResponse(
            id=s.id,
            amenity_id=s.amenity_id,
            day_of_week=s.day_of_week,
            slot_start_time=str(s.slot_start_time),
            slot_end_time=str(s.slot_end_time),
            slot_label=s.slot_label,
            max_bookings=s.max_bookings,
            is_active=s.is_active,
            created_at=s.created_at,
        )
        for s in slots
    ]

@router.post(
    "/amenities/{amenity_id}/time-slots",
    response_model=TimeSlotResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Tạo mới khung giờ cho tiện ích",
)
def create_time_slot(
    amenity_id: uuid.UUID,
    data: TimeSlotCreate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_SLOT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    slot = AmenityService.create_time_slot(
        db=db,
        amenity_id=amenity_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return TimeSlotResponse(
        id=slot.id,
        amenity_id=slot.amenity_id,
        day_of_week=slot.day_of_week,
        slot_start_time=str(slot.slot_start_time),
        slot_end_time=str(slot.slot_end_time),
        slot_label=slot.slot_label,
        max_bookings=slot.max_bookings,
        is_active=slot.is_active,
        created_at=slot.created_at,
    )

@router.put(
    "/amenities/{amenity_id}/time-slots/{slot_id}",
    response_model=TimeSlotResponse,
    summary="Cập nhật khung giờ của tiện ích",
)
def update_time_slot(
    amenity_id: uuid.UUID,
    slot_id: uuid.UUID,
    data: TimeSlotUpdate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_SLOT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    slot = AmenityService.update_time_slot(
        db=db,
        amenity_id=amenity_id,
        slot_id=slot_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return TimeSlotResponse(
        id=slot.id,
        amenity_id=slot.amenity_id,
        day_of_week=slot.day_of_week,
        slot_start_time=str(slot.slot_start_time),
        slot_end_time=str(slot.slot_end_time),
        slot_label=slot.slot_label,
        max_bookings=slot.max_bookings,
        is_active=slot.is_active,
        created_at=slot.created_at,
    )

@router.patch(
    "/amenities/{amenity_id}/time-slots/{slot_id}/status",
    response_model=TimeSlotResponse,
    summary="Bật / Tắt trạng thái của khung giờ",
)
def patch_time_slot_status(
    amenity_id: uuid.UUID,
    slot_id: uuid.UUID,
    data: TimeSlotStatusPatch,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_SLOT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    slot = AmenityService.patch_time_slot_status(
        db=db,
        amenity_id=amenity_id,
        slot_id=slot_id,
        is_active=data.is_active,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return TimeSlotResponse(
        id=slot.id,
        amenity_id=slot.amenity_id,
        day_of_week=slot.day_of_week,
        slot_start_time=str(slot.slot_start_time),
        slot_end_time=str(slot.slot_end_time),
        slot_label=slot.slot_label,
        max_bookings=slot.max_bookings,
        is_active=slot.is_active,
        created_at=slot.created_at,
    )

@router.delete(
    "/amenities/{amenity_id}/time-slots/{slot_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Xóa khung giờ của tiện ích",
)
def delete_time_slot(
    amenity_id: uuid.UUID,
    slot_id: uuid.UUID,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_SLOT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    AmenityService.delete_time_slot(
        db=db,
        amenity_id=amenity_id,
        slot_id=slot_id,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# ==================== BLACKOUT DATES ====================
@router.get(
    "/amenities/{amenity_id}/blackouts",
    response_model=List[BlackoutResponse],
    summary="Danh sách ngày đóng cửa / bảo trì tiện ích",
    dependencies=[Depends(require_permissions("AMENITY:READ"))],
)
def list_blackouts(amenity_id: uuid.UUID, db: Session = Depends(get_db)):
    bos = AmenityService.list_blackouts(db, amenity_id)
    return [
        BlackoutResponse(
            id=b.id,
            amenity_id=b.amenity_id,
            blackout_date=b.blackout_date,
            start_time=str(b.start_time) if b.start_time else None,
            end_time=str(b.end_time) if b.end_time else None,
            reason=b.reason,
            created_by=b.created_by,
            created_at=b.created_at,
        )
        for b in bos
    ]

@router.post(
    "/amenities/{amenity_id}/blackouts",
    response_model=BlackoutResponse,
    status_code=status.HTTP_201_CREATED,
    summary="Thêm ngày đóng cửa / bảo trì tiện ích",
)
def create_blackout(
    amenity_id: uuid.UUID,
    data: BlackoutCreate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_BLACKOUT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    b = AmenityService.create_blackout(
        db=db,
        amenity_id=amenity_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return BlackoutResponse(
        id=b.id,
        amenity_id=b.amenity_id,
        blackout_date=b.blackout_date,
        start_time=str(b.start_time) if b.start_time else None,
        end_time=str(b.end_time) if b.end_time else None,
        reason=b.reason,
        created_by=b.created_by,
        created_at=b.created_at,
    )

@router.put(
    "/amenities/{amenity_id}/blackouts/{blackout_id}",
    response_model=BlackoutResponse,
    summary="Cập nhật ngày đóng cửa / bảo trì tiện ích",
)
def update_blackout(
    amenity_id: uuid.UUID,
    blackout_id: uuid.UUID,
    data: BlackoutUpdate,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_BLACKOUT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    b = AmenityService.update_blackout(
        db=db,
        amenity_id=amenity_id,
        blackout_id=blackout_id,
        data=data,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return BlackoutResponse(
        id=b.id,
        amenity_id=b.amenity_id,
        blackout_date=b.blackout_date,
        start_time=str(b.start_time) if b.start_time else None,
        end_time=str(b.end_time) if b.end_time else None,
        reason=b.reason,
        created_by=b.created_by,
        created_at=b.created_at,
    )

@router.delete(
    "/amenities/{amenity_id}/blackouts/{blackout_id}",
    status_code=status.HTTP_204_NO_CONTENT,
    summary="Xóa ngày đóng cửa bảo trì",
)
def delete_blackout(
    amenity_id: uuid.UUID,
    blackout_id: uuid.UUID,
    request: Request,
    db: Session = Depends(get_db),
    current_user: User = Depends(require_permissions("AMENITY:CONFIGURE_BLACKOUT")),
):
    ip = request.client.host if request.client else None
    ua = request.headers.get("user-agent")
    AmenityService.delete_blackout(
        db=db,
        amenity_id=amenity_id,
        blackout_id=blackout_id,
        user_id=current_user.id,
        client_ip=ip,
        user_agent=ua,
    )
    return Response(status_code=status.HTTP_204_NO_CONTENT)
