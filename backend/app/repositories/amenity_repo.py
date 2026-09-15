import uuid
import json
from datetime import datetime, date, time
from typing import Optional, List, Tuple
from sqlalchemy.orm import Session, joinedload
from sqlalchemy import or_, and_, func

from ..models.amenity import (
    AmenityCategory,
    Amenity,
    AmenityTimeSlot,
    AmenityBlackout,
    AmenityBooking,
)
from ..models.building import Block

class AmenityRepository:
    # ================= CATEGORY =================
    @staticmethod
    def get_categories(db: Session) -> List[Tuple[AmenityCategory, int]]:
        subq = (
            db.query(func.count(Amenity.id))
            .filter(Amenity.category_id == AmenityCategory.id, Amenity.deleted_at.is_(None))
            .scalar_subquery()
        )
        stmt = db.query(AmenityCategory, subq.label("amenities_count")).order_by(AmenityCategory.category_name.asc())
        return stmt.all()

    @staticmethod
    def get_category_by_id(db: Session, category_id: uuid.UUID) -> Optional[AmenityCategory]:
        return db.query(AmenityCategory).filter(AmenityCategory.id == category_id).first()

    @staticmethod
    def get_category_by_code(db: Session, category_code: str) -> Optional[AmenityCategory]:
        return db.query(AmenityCategory).filter(func.lower(AmenityCategory.category_code) == category_code.lower().strip()).first()

    @staticmethod
    def create_category(db: Session, cat: AmenityCategory) -> AmenityCategory:
        db.add(cat)
        db.flush()
        return cat

    @staticmethod
    def delete_category(db: Session, category: AmenityCategory) -> None:
        db.delete(category)
        db.flush()

    @staticmethod
    def count_amenities_in_category(db: Session, category_id: uuid.UUID) -> int:
        return (
            db.query(func.count(Amenity.id))
            .filter(Amenity.category_id == category_id, Amenity.deleted_at.is_(None))
            .scalar() or 0
        )

    # ================= AMENITY =================
    @staticmethod
    def get_amenities_paged(
        db: Session,
        search: Optional[str] = None,
        category_id: Optional[uuid.UUID] = None,
        block_id: Optional[uuid.UUID] = None,
        is_active: Optional[bool] = None,
        page: int = 1,
        limit: int = 20,
        sort: str = "created_at_desc",
    ) -> Tuple[List[Amenity], int]:
        q = (
            db.query(Amenity)
            .options(
                joinedload(Amenity.category),
                joinedload(Amenity.block),
                joinedload(Amenity.time_slots),
            )
            .filter(Amenity.deleted_at.is_(None))
        )

        if search and search.strip():
            term = f"%{search.strip()}%"
            q = q.filter(
                or_(
                    Amenity.amenity_name.ilike(term),
                    Amenity.amenity_code.ilike(term),
                    Amenity.location_detail.ilike(term),
                )
            )

        if category_id:
            q = q.filter(Amenity.category_id == category_id)

        if block_id:
            q = q.filter(Amenity.block_id == block_id)

        if is_active is not None:
            q = q.filter(Amenity.is_active == is_active)

        total = q.count()

        if sort == "name_asc":
            q = q.order_by(Amenity.amenity_name.asc())
        elif sort == "name_desc":
            q = q.order_by(Amenity.amenity_name.desc())
        elif sort == "created_at_asc":
            q = q.order_by(Amenity.created_at.asc())
        else:
            q = q.order_by(Amenity.created_at.desc())

        offset = (page - 1) * limit
        items = q.offset(offset).limit(limit).all()
        return items, total

    @staticmethod
    def get_amenity_by_id(db: Session, amenity_id: uuid.UUID, include_deleted: bool = False) -> Optional[Amenity]:
        q = (
            db.query(Amenity)
            .options(
                joinedload(Amenity.category),
                joinedload(Amenity.block),
                joinedload(Amenity.time_slots),
                joinedload(Amenity.blackouts),
            )
            .filter(Amenity.id == amenity_id)
        )
        if not include_deleted:
            q = q.filter(Amenity.deleted_at.is_(None))
        return q.first()

    @staticmethod
    def get_amenity_by_code(db: Session, amenity_code: str) -> Optional[Amenity]:
        return (
            db.query(Amenity)
            .filter(
                func.lower(Amenity.amenity_code) == amenity_code.lower().strip(),
                Amenity.deleted_at.is_(None),
            )
            .first()
        )

    @staticmethod
    def create_amenity(db: Session, amenity: Amenity) -> Amenity:
        db.add(amenity)
        db.flush()
        return amenity

    @staticmethod
    def count_amenity_bookings(db: Session, amenity_id: uuid.UUID) -> int:
        return db.query(func.count(AmenityBooking.id)).filter(AmenityBooking.amenity_id == amenity_id).scalar() or 0

    # ================= TIME SLOTS =================
    @staticmethod
    def get_time_slots(db: Session, amenity_id: uuid.UUID) -> List[AmenityTimeSlot]:
        return (
            db.query(AmenityTimeSlot)
            .filter(AmenityTimeSlot.amenity_id == amenity_id)
            .order_by(AmenityTimeSlot.day_of_week.asc(), AmenityTimeSlot.slot_start_time.asc())
            .all()
        )

    @staticmethod
    def get_time_slot_by_id(db: Session, slot_id: uuid.UUID) -> Optional[AmenityTimeSlot]:
        return db.query(AmenityTimeSlot).filter(AmenityTimeSlot.id == slot_id).first()

    @staticmethod
    def check_time_slot_overlap(
        db: Session,
        amenity_id: uuid.UUID,
        day_of_week: int,
        start_time: time,
        end_time: time,
        exclude_slot_id: Optional[uuid.UUID] = None,
    ) -> bool:
        # Check if existing.slot_start_time < new.slot_end_time AND existing.slot_end_time > new.slot_start_time
        q = db.query(AmenityTimeSlot).filter(
            AmenityTimeSlot.amenity_id == amenity_id,
            AmenityTimeSlot.day_of_week == day_of_week,
            AmenityTimeSlot.slot_start_time < end_time,
            AmenityTimeSlot.slot_end_time > start_time,
        )
        if exclude_slot_id:
            q = q.filter(AmenityTimeSlot.id != exclude_slot_id)
        return q.first() is not None

    @staticmethod
    def create_time_slot(db: Session, slot: AmenityTimeSlot) -> AmenityTimeSlot:
        db.add(slot)
        db.flush()
        return slot

    @staticmethod
    def delete_time_slot(db: Session, slot: AmenityTimeSlot) -> None:
        db.delete(slot)
        db.flush()

    # ================= BLACKOUTS =================
    @staticmethod
    def get_blackouts(db: Session, amenity_id: uuid.UUID) -> List[AmenityBlackout]:
        return (
            db.query(AmenityBlackout)
            .filter(AmenityBlackout.amenity_id == amenity_id)
            .order_by(AmenityBlackout.blackout_date.asc(), AmenityBlackout.start_time.asc())
            .all()
        )

    @staticmethod
    def get_blackout_by_id(db: Session, blackout_id: uuid.UUID) -> Optional[AmenityBlackout]:
        return db.query(AmenityBlackout).filter(AmenityBlackout.id == blackout_id).first()

    @staticmethod
    def create_blackout(db: Session, blackout: AmenityBlackout) -> AmenityBlackout:
        db.add(blackout)
        db.flush()
        return blackout

    @staticmethod
    def delete_blackout(db: Session, blackout: AmenityBlackout) -> None:
        db.delete(blackout)
        db.flush()

    # ================= META BLOCKS =================
    @staticmethod
    def get_blocks(db: Session) -> List[Block]:
        return db.query(Block).filter(Block.deleted_at.is_(None)).order_by(Block.block_name.asc()).all()

    @staticmethod
    def get_block_by_id(db: Session, block_id: uuid.UUID) -> Optional[Block]:
        return db.query(Block).filter(Block.id == block_id, Block.deleted_at.is_(None)).first()
