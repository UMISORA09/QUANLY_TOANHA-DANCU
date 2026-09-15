import uuid
from datetime import datetime, timedelta, timezone
from typing import Optional, List
import bcrypt
import jwt
from fastapi import Depends, HTTPException, status
from fastapi.security import OAuth2PasswordBearer
from sqlalchemy.orm import Session, joinedload

from .config import settings
from .database import get_db
from ..models.auth import User, Role, Permission, UserRole, RolePermission

oauth2_scheme = OAuth2PasswordBearer(tokenUrl=f"{settings.API_V1_STR}/auth/login", auto_error=False)

def verify_password(plain_password: str, hashed_password: str) -> bool:
    try:
        return bcrypt.checkpw(plain_password.encode("utf-8"), hashed_password.encode("utf-8"))
    except Exception:
        # Fallback for plain/legacy development accounts if any
        return plain_password == hashed_password

def get_password_hash(password: str) -> str:
    salt = bcrypt.gensalt()
    return bcrypt.hashpw(password.encode("utf-8"), salt).decode("utf-8")

def create_access_token(data: dict, expires_delta: Optional[timedelta] = None) -> str:
    to_encode = data.copy()
    expire = datetime.now(timezone.utc) + (expires_delta or timedelta(minutes=settings.ACCESS_TOKEN_EXPIRE_MINUTES))
    to_encode.update({"exp": expire})
    return jwt.encode(to_encode, settings.JWT_SECRET_KEY, algorithm=settings.JWT_ALGORITHM)

def decode_access_token(token: str) -> Optional[dict]:
    try:
        payload = jwt.decode(token, settings.JWT_SECRET_KEY, algorithms=[settings.JWT_ALGORITHM])
        return payload
    except jwt.PyJWTError:
        return None

def get_current_user(
    token: Optional[str] = Depends(oauth2_scheme),
    db: Session = Depends(get_db)
) -> User:
    if not token:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Yêu cầu xác thực Bearer Token để truy cập tài nguyên này.",
            headers={"WWW-Authenticate": "Bearer"},
        )
    payload = decode_access_token(token)
    if not payload or "sub" not in payload:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token không hợp lệ hoặc đã hết hạn.",
            headers={"WWW-Authenticate": "Bearer"},
        )
    user_id_str = payload.get("sub")
    try:
        user_uuid = uuid.UUID(user_id_str)
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Định dạng User ID trong token không hợp lệ.",
        )

    user = (
        db.query(User)
        .options(
            joinedload(User.user_roles)
            .joinedload(UserRole.role)
            .joinedload(Role.role_permissions)
            .joinedload(RolePermission.permission)
        )
        .filter(User.id == user_uuid, User.deleted_at.is_(None))
        .first()
    )
    if not user:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Không tìm thấy tài khoản người dùng tương ứng.",
        )
    if user.status != "ACTIVE":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Tài khoản đang bị khóa hoặc ngưng hoạt động.",
        )
    return user

def get_user_permissions(user: User) -> set[str]:
    perms = set()
    for ur in user.user_roles:
        role = ur.role
        if not role:
            continue
        # SUPER_ADMIN and BUILDING_MANAGER have all system permissions
        if role.role_code in ("SUPER_ADMIN", "BUILDING_MANAGER"):
            perms.add("*")
            perms.add("AMENITY:READ")
            perms.add("AMENITY:CREATE")
            perms.add("AMENITY:UPDATE")
            perms.add("AMENITY:DELETE")
            perms.add("AMENITY:CONFIGURE_SLOT")
            perms.add("AMENITY:CONFIGURE_BLACKOUT")
        for rp in role.role_permissions:
            if rp.permission and rp.permission.permission_code:
                perms.add(rp.permission.permission_code)
    return perms

def require_permissions(*required_perms: str):
    def permission_checker(current_user: User = Depends(get_current_user)):
        user_perms = get_user_permissions(current_user)
        if "*" in user_perms:
            return current_user
        for rp in required_perms:
            if rp in user_perms:
                return current_user
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Bạn không có quyền thực hiện thao tác này trên phân hệ tiện ích.",
        )
    return permission_checker
