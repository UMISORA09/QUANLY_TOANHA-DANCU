import uuid
from datetime import datetime
from sqlalchemy import (
    Column,
    String,
    Text,
    UnicodeText,
    DateTime,
    ForeignKey,
)
from sqlalchemy.dialects.mssql import UNIQUEIDENTIFIER
from ..core.database import Base
from .auth import User

class AuditLog(Base):
    __tablename__ = "audit_logs"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    table_name = Column(String(80), nullable=False)
    record_id = Column(UNIQUEIDENTIFIER, nullable=False)
    action = Column(String(20), nullable=False)  # 'INSERT', 'UPDATE', 'DELETE', 'STATUS_CHANGE'
    performed_by_user_id = Column(UNIQUEIDENTIFIER, ForeignKey("users.id"), nullable=True)
    client_ip_address = Column(String(45), nullable=True)
    user_agent = Column(Text, nullable=True)
    old_data = Column(UnicodeText, nullable=True)
    new_data = Column(UnicodeText, nullable=True)
    changed_fields = Column(UnicodeText, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
