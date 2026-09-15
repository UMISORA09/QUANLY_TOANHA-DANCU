import uuid
from datetime import datetime, date, time
from decimal import Decimal
from typing import Optional, List, Any
from pydantic import BaseModel, Field, field_validator, model_validator, ConfigDict

# ==================== CATEGORY SCHEMAS ====================
class CategoryBase(BaseModel):
    category_name: str = Field(..., min_length=1, max_length=100, description="Tên danh mục tiện ích")
    category_code: str = Field(..., min_length=1, max_length=50, description="Mã danh mục (duy nhất)")
    icon_name: Optional[str] = Field(None, max_length=50, description="Icon đại diện")
    description: Optional[str] = Field(None, description="Mô tả danh mục")

    @field_validator("category_code")
    @classmethod
    def clean_category_code(cls, v: str) -> str:
        code = v.strip().upper()
        if not code:
            raise ValueError("Mã danh mục không được để trống")
        return code

    @field_validator("category_name")
    @classmethod
    def clean_category_name(cls, v: str) -> str:
        name = v.strip()
        if not name:
            raise ValueError("Tên danh mục không được để trống")
        return name

class CategoryCreate(CategoryBase):
    pass

class CategoryUpdate(CategoryBase):
    pass

class CategoryResponse(CategoryBase):
    id: uuid.UUID
    created_at: datetime
    amenities_count: int = 0

    model_config = ConfigDict(from_attributes=True)


# ==================== TIME SLOT SCHEMAS ====================
class TimeSlotBase(BaseModel):
    day_of_week: int = Field(..., ge=0, le=6, description="0: Chủ nhật, 1-6: Thứ 2 đến Thứ 7")
    slot_start_time: str = Field(..., description="Giờ bắt đầu (định dạng HH:MM hoặc HH:MM:SS)")
    slot_end_time: str = Field(..., description="Giờ kết thúc (định dạng HH:MM hoặc HH:MM:SS)")
    slot_label: Optional[str] = Field(None, max_length=60, description="Nhãn khung giờ")
    max_bookings: int = Field(1, gt=0, description="Số lượng booking tối đa cùng lúc trong slot này")
    is_active: bool = Field(True, description="Trạng thái kích hoạt của slot")

    @field_validator("slot_start_time", "slot_end_time")
    @classmethod
    def format_time_str(cls, v: str) -> str:
        val = v.strip()
        parts = val.split(":")
        if len(parts) == 2:
            return f"{int(parts[0]):02d}:{int(parts[1]):02d}:00"
        elif len(parts) == 3:
            return f"{int(parts[0]):02d}:{int(parts[1]):02d}:{int(parts[2].split('.')[0]):02d}"
        return val

    @model_validator(mode="after")
    def check_start_before_end(self):
        if self.slot_start_time >= self.slot_end_time:
            raise ValueError("Giờ bắt đầu phải nhỏ hơn giờ kết thúc")
        return self

class TimeSlotCreate(TimeSlotBase):
    pass

class TimeSlotUpdate(TimeSlotBase):
    pass

class TimeSlotStatusPatch(BaseModel):
    is_active: bool

class TimeSlotResponse(BaseModel):
    id: uuid.UUID
    amenity_id: uuid.UUID
    day_of_week: int
    slot_start_time: str
    slot_end_time: str
    slot_label: Optional[str]
    max_bookings: int
    is_active: bool
    created_at: datetime

    model_config = ConfigDict(from_attributes=True)


# ==================== BLACKOUT SCHEMAS ====================
class BlackoutBase(BaseModel):
    blackout_date: date = Field(..., description="Ngày đóng cửa/bảo trì")
    start_time: Optional[str] = Field(None, description="Giờ bắt đầu (null nếu đóng cả ngày)")
    end_time: Optional[str] = Field(None, description="Giờ kết thúc (null nếu đóng cả ngày)")
    reason: str = Field(..., min_length=1, max_length=255, description="Lý do đóng cửa")

    @field_validator("reason")
    @classmethod
    def clean_reason(cls, v: str) -> str:
        val = v.strip()
        if not val:
            raise ValueError("Lý do đóng cửa bảo trì không được để trống")
        return val

    @field_validator("start_time", "end_time")
    @classmethod
    def format_blackout_time(cls, v: Optional[str]) -> Optional[str]:
        if not v or not v.strip():
            return None
        parts = v.strip().split(":")
        if len(parts) == 2:
            return f"{int(parts[0]):02d}:{int(parts[1]):02d}:00"
        elif len(parts) == 3:
            return f"{int(parts[0]):02d}:{int(parts[1]):02d}:{int(parts[2].split('.')[0]):02d}"
        return v.strip()

    @model_validator(mode="after")
    def validate_blackout_times(self):
        st = self.start_time
        et = self.end_time
        if (st is None and et is not None) or (st is not None and et is None):
            raise ValueError("Phải cung cấp cả giờ bắt đầu và giờ kết thúc, hoặc để trống cả hai (đóng cả ngày)")
        if st is not None and et is not None and st >= et:
            raise ValueError("Giờ bắt đầu phải nhỏ hơn giờ kết thúc")
        return self

class BlackoutCreate(BlackoutBase):
    pass

class BlackoutUpdate(BlackoutBase):
    pass

class BlackoutResponse(BaseModel):
    id: uuid.UUID
    amenity_id: uuid.UUID
    blackout_date: date
    start_time: Optional[str]
    end_time: Optional[str]
    reason: str
    created_by: Optional[uuid.UUID]
    created_at: datetime

    model_config = ConfigDict(from_attributes=True)


# ==================== AMENITY SCHEMAS ====================
class AmenityBase(BaseModel):
    category_id: uuid.UUID = Field(..., description="ID danh mục tiện ích")
    block_id: Optional[uuid.UUID] = Field(None, description="ID tòa nhà (null nếu dùng chung tòa)")
    amenity_name: str = Field(..., min_length=1, max_length=150, description="Tên tiện ích")
    amenity_code: str = Field(..., min_length=1, max_length=60, description="Mã tiện ích (duy nhất)")
    location_detail: str = Field(..., min_length=1, max_length=255, description="Vị trí chi tiết")

    max_capacity_per_slot: int = Field(10, gt=0, description="Sức chứa tối đa số người trên 1 booking/slot")
    hourly_rate: Decimal = Field(Decimal("0.00"), ge=0, description="Đơn giá thuê theo giờ")
    security_deposit_required: Decimal = Field(Decimal("0.00"), ge=0, description="Tiền đặt cọc bắt buộc")

    advance_booking_days_limit: int = Field(7, ge=0, description="Số ngày cho phép đặt trước tối đa")
    min_cancel_hours_before: int = Field(12, ge=0, description="Thời gian hủy trước tối thiểu (giờ)")
    requires_admin_approval: bool = Field(False, description="Yêu cầu BQL tòa nhà phê duyệt")

    rules_and_regulations: Optional[str] = Field(None, description="Nội quy & quy định sử dụng")
    cover_image_url: Optional[str] = Field(None, max_length=500, description="URL ảnh đại diện")
    gallery_images: Optional[list[str]] = Field(default_factory=list, description="Danh sách ảnh gallery")
    is_active: bool = Field(True, description="Trạng thái hoạt động")

    @field_validator("amenity_code")
    @classmethod
    def clean_amenity_code(cls, v: str) -> str:
        code = v.strip().upper()
        if not code:
            raise ValueError("Mã tiện ích không được để trống")
        return code

    @field_validator("amenity_name")
    @classmethod
    def clean_amenity_name(cls, v: str) -> str:
        name = v.strip()
        if not name:
            raise ValueError("Tên tiện ích không được để trống")
        return name

    @field_validator("location_detail")
    @classmethod
    def clean_location_detail(cls, v: str) -> str:
        loc = v.strip()
        if not loc:
            raise ValueError("Vị trí chi tiết không được để trống")
        return loc

class AmenityCreate(AmenityBase):
    pass

class AmenityUpdate(AmenityBase):
    version: int = Field(..., ge=1, description="Phiên bản dữ liệu phục vụ Optimistic Concurrency Control (OCC)")

class AmenityStatusPatch(BaseModel):
    is_active: bool
    version: Optional[int] = Field(None, ge=1, description="Phiên bản dữ liệu (tùy chọn) phục vụ OCC")

class AmenityResponse(AmenityBase):
    id: uuid.UUID
    version: int = 1
    created_at: datetime
    updated_at: datetime
    deleted_at: Optional[datetime] = None

    category_name: Optional[str] = None
    category_code: Optional[str] = None
    block_name: Optional[str] = None
    block_code: Optional[str] = None
    time_slots_count: int = 0
    active_time_slots_count: int = 0
    max_bookings_per_slot: int = 1

    model_config = ConfigDict(from_attributes=True)

class AmenityListResponse(BaseModel):
    items: List[AmenityResponse]
    total: int
    page: int
    limit: int
    total_pages: int
