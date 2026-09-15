import uuid
from datetime import datetime, date, time
from sqlalchemy import (
    Column,
    String,
    Unicode,
    Text,
    UnicodeText,
    Integer,
    Numeric,
    Boolean,
    DateTime,
    Date,
    Time,
    ForeignKey,
)
from sqlalchemy.dialects.mssql import UNIQUEIDENTIFIER
from sqlalchemy.orm import relationship
from ..core.database import Base
from .building import Block

class AmenityCategory(Base):
    __tablename__ = "amenity_categories"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    category_name = Column(Unicode(100), unique=True, nullable=False)
    category_code = Column(String(50), unique=True, nullable=False)
    icon_name = Column(String(50), nullable=True)
    description = Column(UnicodeText, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    amenities = relationship("Amenity", back_populates="category")


class Amenity(Base):
    __tablename__ = "amenities"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    category_id = Column(UNIQUEIDENTIFIER, ForeignKey("amenity_categories.id"), nullable=False)
    block_id = Column(UNIQUEIDENTIFIER, ForeignKey("blocks.id"), nullable=True)
    amenity_name = Column(Unicode(150), nullable=False)
    amenity_code = Column(String(60), unique=True, nullable=False)
    location_detail = Column(Unicode(255), nullable=False)

    max_capacity_per_slot = Column(Integer, default=10, nullable=False)
    hourly_rate = Column(Numeric(12, 2), default=0.00, nullable=False)
    security_deposit_required = Column(Numeric(12, 2), default=0.00, nullable=False)

    advance_booking_days_limit = Column(Integer, default=7, nullable=False)
    min_cancel_hours_before = Column(Integer, default=12, nullable=False)
    requires_admin_approval = Column(Boolean, default=False, nullable=False)

    rules_and_regulations = Column(UnicodeText, nullable=True)
    cover_image_url = Column(String(500), nullable=True)
    gallery_images = Column(UnicodeText, default="[]", nullable=False)
    is_active = Column(Boolean, default=True, nullable=False)
    version = Column(Integer, default=1, nullable=False)

    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    deleted_at = Column(DateTime, nullable=True)

    category = relationship("AmenityCategory", back_populates="amenities")
    block = relationship("Block")
    time_slots = relationship("AmenityTimeSlot", back_populates="amenity", cascade="all, delete-orphan")
    blackouts = relationship("AmenityBlackout", back_populates="amenity", cascade="all, delete-orphan")
    bookings = relationship("AmenityBooking", back_populates="amenity")


class AmenityTimeSlot(Base):
    __tablename__ = "amenity_time_slots"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    amenity_id = Column(UNIQUEIDENTIFIER, ForeignKey("amenities.id"), nullable=False)
    day_of_week = Column(Integer, nullable=False)  # 0: Sunday, 1-6: Mon-Sat
    slot_start_time = Column(Time, nullable=False)
    slot_end_time = Column(Time, nullable=False)
    slot_label = Column(Unicode(100), nullable=True)
    max_bookings = Column(Integer, default=1, nullable=False)
    is_active = Column(Boolean, default=True, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    amenity = relationship("Amenity", back_populates="time_slots")


class AmenityBlackout(Base):
    __tablename__ = "amenity_blackouts"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    amenity_id = Column(UNIQUEIDENTIFIER, ForeignKey("amenities.id"), nullable=False)
    blackout_date = Column(Date, nullable=False)
    start_time = Column(Time, nullable=True)
    end_time = Column(Time, nullable=True)
    reason = Column(Unicode(255), nullable=False)
    created_by = Column(UNIQUEIDENTIFIER, ForeignKey("users.id"), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    amenity = relationship("Amenity", back_populates="blackouts")


class AmenityBooking(Base):
    __tablename__ = "amenity_bookings"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    booking_code = Column(String(60), unique=True, nullable=False)
    amenity_id = Column(UNIQUEIDENTIFIER, ForeignKey("amenities.id"), nullable=False)
    apartment_id = Column(UNIQUEIDENTIFIER, nullable=False)
    resident_user_id = Column(UNIQUEIDENTIFIER, ForeignKey("users.id"), nullable=False)

    booking_date = Column(Date, nullable=False)
    start_time = Column(Time, nullable=False)
    end_time = Column(Time, nullable=False)
    attendee_count = Column(Integer, default=1, nullable=False)

    total_amount = Column(Numeric(12, 2), default=0.00, nullable=False)
    deposit_amount = Column(Numeric(12, 2), default=0.00, nullable=False)
    is_paid = Column(Boolean, default=True, nullable=False)
    status = Column(String(50), default="PENDING", nullable=False)

    checkin_qr_code = Column(String(120), unique=True, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    deleted_at = Column(DateTime, nullable=True)

    amenity = relationship("Amenity", back_populates="bookings")
