import uuid
from datetime import datetime, date
from typing import Optional, List
from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, Field
from sqlalchemy.orm import Session, joinedload

from ..core.config import settings
from ..core.database import get_db
from ..core.security import (
    verify_password,
    get_password_hash,
    create_access_token,
    get_current_user,
    get_user_permissions,
)
from ..models.auth import User, Role, UserRole

router = APIRouter(prefix="/auth", tags=["Authentication"])

class LoginRequest(BaseModel):
    identifier: str = Field(..., description="Email, số điện thoại hoặc username")
    password: str = Field(..., description="Mật khẩu")

class UserInfoResponse(BaseModel):
    id: uuid.UUID
    username: Optional[str]
    email: Optional[str]
    phone_number: str
    full_name: str
    avatar_url: Optional[str]
    roles: List[str]
    permissions: List[str]

class LoginResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: UserInfoResponse

def ensure_seed_users(db: Session):
    # Check if admin user exists; if not, seed Admin and Resident users
    admin_user = db.query(User).filter(
        (User.email == "admin@smartcassavas.vn") | (User.phone_number == "0934567890")
    ).first()
    
    super_admin_role = db.query(Role).filter(Role.role_code == "SUPER_ADMIN").first()
    resident_role = db.query(Role).filter(Role.role_code == "RESIDENT_OWNER").first()

    if not admin_user and super_admin_role:
        hashed = get_password_hash(settings.ADMIN_DEFAULT_PASSWORD)
        new_admin = User(
            id=uuid.uuid4(),
            username="admin",
            phone_number="0934567890",
            email="admin@smartcassavas.vn",
            national_id_number="001099000001",
            password_hash=hashed,
            full_name="Quản Trị Viên Hệ Thống",
            status="ACTIVE",
            created_at=datetime.now(),
            updated_at=datetime.now(),
        )
        db.add(new_admin)
        db.flush()
        db.add(UserRole(
            id=uuid.uuid4(),
            user_id=new_admin.id,
            role_id=super_admin_role.id,
            is_primary=True,
            assigned_at=datetime.now(),
        ))
        db.commit()

    resident_user = db.query(User).filter(
        (User.email == "cudan@smartcassavas.vn") | (User.phone_number == "0912345678")
    ).first()

    if not resident_user and resident_role:
        hashed = get_password_hash(settings.ADMIN_DEFAULT_PASSWORD)
        new_res = User(
            id=uuid.uuid4(),
            username="cudan",
            phone_number="0912345678",
            email="cudan@smartcassavas.vn",
            national_id_number="001099000002",
            password_hash=hashed,
            full_name="Cư Dân Chung Cư",
            status="ACTIVE",
            created_at=datetime.now(),
            updated_at=datetime.now(),
        )
        db.add(new_res)
        db.flush()
        db.add(UserRole(
            id=uuid.uuid4(),
            user_id=new_res.id,
            role_id=resident_role.id,
            is_primary=True,
            assigned_at=datetime.now(),
        ))
        db.commit()

@router.post("/login", response_model=LoginResponse, summary="Đăng nhập tài khoản")
def login(req: LoginRequest, db: Session = Depends(get_db)):
    ensure_seed_users(db)
    ident = req.identifier.strip().lower()

    user = (
        db.query(User)
        .options(
            joinedload(User.user_roles).joinedload(UserRole.role)
        )
        .filter(
            (User.phone_number == req.identifier.strip()) |
            (User.email.ilike(ident)) |
            (User.username.ilike(ident)),
            User.deleted_at.is_(None),
        )
        .first()
    )

    if not user:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Thông tin đăng nhập không chính xác.",
        )

    if not verify_password(req.password, user.password_hash):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Mật khẩu không chính xác.",
        )

    if user.status != "ACTIVE":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Tài khoản đã bị tạm khóa.",
        )

    roles = [ur.role.role_code for ur in user.user_roles if ur.role]
    perms = list(get_user_permissions(user))

    token = create_access_token(data={"sub": str(user.id), "roles": roles})

    return LoginResponse(
        access_token=token,
        token_type="bearer",
        user=UserInfoResponse(
            id=user.id,
            username=user.username,
            email=user.email,
            phone_number=user.phone_number,
            full_name=user.full_name,
            avatar_url=user.avatar_url,
            roles=roles,
            permissions=perms,
        ),
    )

@router.get("/me", response_model=UserInfoResponse, summary="Lấy thông tin người dùng đang đăng nhập")
def me(current_user: User = Depends(get_current_user)):
    roles = [ur.role.role_code for ur in current_user.user_roles if ur.role]
    perms = list(get_user_permissions(current_user))
    return UserInfoResponse(
        id=current_user.id,
        username=current_user.username,
        email=current_user.email,
        phone_number=current_user.phone_number,
        full_name=current_user.full_name,
        avatar_url=current_user.avatar_url,
        roles=roles,
        permissions=perms,
    )
