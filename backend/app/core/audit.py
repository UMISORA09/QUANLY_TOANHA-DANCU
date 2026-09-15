import json
import uuid
from typing import Any, Optional
from sqlalchemy.orm import Session
from ..models.audit import AuditLog

class AuditService:
    @staticmethod
    def log_change(
        db: Session,
        table_name: str,
        record_id: uuid.UUID | str,
        action: str,
        performed_by_user_id: Optional[uuid.UUID | str] = None,
        old_data: Optional[dict[str, Any]] = None,
        new_data: Optional[dict[str, Any]] = None,
        changed_fields: Optional[list[str]] = None,
        client_ip: Optional[str] = None,
        user_agent: Optional[str] = None,
    ) -> AuditLog:
        # Filter sensitive fields
        sensitive_keys = {"password", "password_hash", "token", "secret", "mfa_secret"}
        
        def sanitize(d: Optional[dict[str, Any]]) -> Optional[str]:
            if not d:
                return None
            sanitized = {k: ("***" if k in sensitive_keys else str(v) if isinstance(v, (uuid.UUID)) else v) for k, v in d.items()}
            return json.dumps(sanitized, ensure_ascii=False, default=str)

        rec_uuid = uuid.UUID(str(record_id)) if isinstance(record_id, str) else record_id
        user_uuid = uuid.UUID(str(performed_by_user_id)) if performed_by_user_id else None

        audit_entry = AuditLog(
            id=uuid.uuid4(),
            table_name=table_name,
            record_id=rec_uuid,
            action=action.upper(),
            performed_by_user_id=user_uuid,
            client_ip_address=client_ip or "127.0.0.1",
            user_agent=user_agent or "FastAPI-Client",
            old_data=sanitize(old_data),
            new_data=sanitize(new_data),
            changed_fields=json.dumps(changed_fields or [], ensure_ascii=False) if changed_fields else None,
        )
        db.add(audit_entry)
        # Note: we do not commit here; caller commit commits both entity + audit log in single transaction
        return audit_entry
