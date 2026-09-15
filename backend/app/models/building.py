import uuid
from datetime import datetime
from sqlalchemy import (
    Column,
    String,
    Unicode,
    Text,
    UnicodeText,
    Integer,
    DateTime,
    ForeignKey,
)
from sqlalchemy.dialects.mssql import UNIQUEIDENTIFIER
from sqlalchemy.orm import relationship
from ..core.database import Base

class Block(Base):
    __tablename__ = "blocks"

    id = Column(UNIQUEIDENTIFIER, primary_key=True, default=uuid.uuid4)
    block_code = Column(String(30), unique=True, nullable=False)
    block_name = Column(Unicode(100), nullable=False)
    total_floors = Column(Integer, default=1, nullable=False)
    total_apartments = Column(Integer, default=0, nullable=False)
    address_line = Column(UnicodeText, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow, nullable=False)
    deleted_at = Column(DateTime, nullable=True)
