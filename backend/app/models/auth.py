import uuid
from datetime import datetime, date
from sqlalchemy import (
    Column,
    String,
    Unicode,
    Text,
    UnicodeText,
    Integer,
    Boolean,
    DateTime,
    Date,
    ForeignKey,
)
from sqlalchemy.dialects.mssql import UNIQUEIDENTIFIER
from sqlalchemy.orm import relationship
from ..core.database import Base

class Role(Base):
    __tablename__ = "roles"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    role_code = Column(String(50), unique=True, nullable=False)
    role_name = Column(Unicode(100), nullable=False)
    description = Column(UnicodeText, nullable=True)
    is_system_role = Column(Boolean, default=False, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    deleted_at = Column(DateTime, nullable=True)

    role_permissions = relationship("RolePermission", back_populates="role")
    user_roles = relationship("UserRole", back_populates="role")


class Permission(Base):
    __tablename__ = "permissions"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    module = Column(String(50), nullable=False)
    permission_code = Column(String(80), unique=True, nullable=False)
    permission_name = Column(Unicode(150), nullable=False)
    description = Column(UnicodeText, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    role_permissions = relationship("RolePermission", back_populates="permission")


class RolePermission(Base):
    __tablename__ = "role_permissions"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    role_id = Column(UNIQUEIDENTIFIER, ForeignKey("roles.id"), nullable=False)
    permission_id = Column(UNIQUEIDENTIFIER, ForeignKey("permissions.id"), nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    role = relationship("Role", back_populates="role_permissions")
    permission = relationship("Permission", back_populates="role_permissions")


class User(Base):
    __tablename__ = "users"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    username = Column(String(60), unique=True, nullable=True)
    phone_number = Column(String(20), unique=True, nullable=False)
    email = Column(String(120), unique=True, nullable=True)
    password_hash = Column(String(255), nullable=False)
    full_name = Column(Unicode(150), nullable=False)
    avatar_url = Column(String(500), nullable=True)
    gender = Column(String(50), default="OTHER", nullable=True)
    date_of_birth = Column(Date, nullable=True)
    national_id_number = Column(String(25), unique=True, nullable=True)
    status = Column(String(50), default="ACTIVE", nullable=False)
    mfa_enabled = Column(Boolean, default=False, nullable=False)
    mfa_secret = Column(String(120), nullable=True)
    last_login_at = Column(DateTime, nullable=True)
    last_login_ip = Column(String(45), nullable=True)
    failed_login_attempts = Column(Integer, default=0, nullable=False)
    lockout_until = Column(DateTime, nullable=True)
    fcm_device_token = Column(String(500), nullable=True)
    extra_preferences = Column(UnicodeText, default="{}", nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    deleted_at = Column(DateTime, nullable=True)

    user_roles = relationship("UserRole", back_populates="user", foreign_keys="UserRole.user_id")


class UserRole(Base):
    __tablename__ = "user_roles"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    user_id = Column(UNIQUEIDENTIFIER, ForeignKey("users.id"), nullable=False)
    role_id = Column(UNIQUEIDENTIFIER, ForeignKey("roles.id"), nullable=False)
    is_primary = Column(Boolean, default=False, nullable=False)
    assigned_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    assigned_by = Column(UNIQUEIDENTIFIER, ForeignKey("users.id"), nullable=True)

    user = relationship("User", foreign_keys=[user_id], back_populates="user_roles")
    role = relationship("Role", back_populates="user_roles")
