from sqlalchemy.ext.asyncio import AsyncSession
from datetime import datetime, timezone
from typing import Optional, Dict, Any, List
from uuid import UUID
import json
from functools import wraps

from src.models.database.audit_log import AuditLog
from src.core.cache.redis_manager import RedisManager

class AuditService:
    def __init__(self, redis_manager: RedisManager, async_session_maker):
        self.redis = redis_manager
        self.session_maker = async_session_maker
    
    async def log_action(
        self,
        action: str,
        actor_role: str,
        actor_id: Optional[str] = None,
        certificate_id: Optional[UUID] = None,
        ip_address: Optional[str] = None,
        user_agent: Optional[str] = None,
        success: bool = True,
        details: Optional[Dict[str, Any]] = None,
        async_mode: bool = True
    ):
        """
        Log an audit event
        
        Args:
            action: The action performed (verify, view, revoke, upload, etc.)
            actor_role: Role of the actor (issuer, holder, verifier, admin)
            actor_id: Identifier of the actor (user ID, email, API key ID)
            certificate_id: UUID of affected certificate (if any)
            ip_address: Client IP address
            user_agent: Client user agent
            success: Whether the action succeeded
            details: Additional structured data
            async_mode: If True, log asynchronously (fire and forget)
        """
        audit_entry = AuditLog(
            action=action,
            actor_role=actor_role,
            actor_id=actor_id,
            certificate_id=certificate_id,
            ip_address=ip_address,
            user_agent=user_agent,
            success=success,
            details=details or {},
            timestamp=datetime.now(timezone.utc)
        )
        
        if async_mode:
            # Fire and forget
            import asyncio
            asyncio.create_task(self._save_audit_log(audit_entry))
        else:
            await self._save_audit_log(audit_entry)
    
    async def _save_audit_log(self, audit_entry: AuditLog):
        """Save audit log to database"""
        try:
            async with self.session_maker() as session:
                session.add(audit_entry)
                await session.commit()
        except Exception as e:
            # Log failure but don't crash the main flow
            print(f"Failed to save audit log: {e}")
    
    async def get_certificate_audit_trail(
        self,
        certificate_id: UUID,
        limit: int = 100,
        offset: int = 0
    ) -> List[Dict[str, Any]]:
        """Retrieve audit trail for a specific certificate"""
        from sqlalchemy import select
        
        async with self.session_maker() as session:
            stmt = select(AuditLog).where(
                AuditLog.certificate_id == certificate_id
            ).order_by(
                AuditLog.timestamp.desc()
            ).offset(offset).limit(limit)
            
            result = await session.execute(stmt)
            logs = result.scalars().all()
            
            return [
                {
                    "id": log.id,
                    "action": log.action,
                    "actor_role": log.actor_role,
                    "actor_id": log.actor_id,
                    "ip_address": log.ip_address,
                    "success": log.success,
                    "details": log.details,
                    "timestamp": log.timestamp.isoformat()
                }
                for log in logs
            ]
    
    async def get_verification_stats(
        self,
        certificate_id: UUID,
        days: int = 30
    ) -> Dict[str, Any]:
        """Get verification statistics for a certificate"""
        from sqlalchemy import select, func
        from datetime import timedelta
        
        cutoff = datetime.now(timezone.utc) - timedelta(days=days)
        
        async with self.session_maker() as session:
            # Total verifications
            stmt = select(func.count()).where(
                AuditLog.certificate_id == certificate_id,
                AuditLog.action == "verify",
                AuditLog.timestamp >= cutoff,
                AuditLog.success == True
            )
            total_verifications = (await session.execute(stmt)).scalar() or 0
            
            # Unique verifiers (by IP)
            stmt = select(func.count(func.distinct(AuditLog.ip_address))).where(
                AuditLog.certificate_id == certificate_id,
                AuditLog.action == "verify",
                AuditLog.timestamp >= cutoff
            )
            unique_verifiers = (await session.execute(stmt)).scalar() or 0
            
            # Failed attempts
            stmt = select(func.count()).where(
                AuditLog.certificate_id == certificate_id,
                AuditLog.action == "verify",
                AuditLog.timestamp >= cutoff,
                AuditLog.success == False
            )
            failed_attempts = (await session.execute(stmt)).scalar() or 0
            
            return {
                "certificate_id": str(certificate_id),
                "period_days": days,
                "total_verifications": total_verifications,
                "unique_verifiers": unique_verifiers,
                "failed_attempts": failed_attempts,
                "success_rate": (
                    (total_verifications / (total_verifications + failed_attempts)) * 100
                    if (total_verifications + failed_attempts) > 0 else 0
                )
            }
    
    async def prune_old_logs(self, retention_days: int = 90):
        """Delete audit logs older than retention period"""
        from sqlalchemy import delete
        from datetime import timedelta
        
        cutoff = datetime.now(timezone.utc) - timedelta(days=retention_days)
        
        async with self.session_maker() as session:
            stmt = delete(AuditLog).where(AuditLog.timestamp < cutoff)
            result = await session.execute(stmt)
            await session.commit()
            
            return {"deleted_count": result.rowcount}

# Decorator for automatic audit logging
def audit_log(action: str):
    """Decorator to automatically log function calls"""
    def decorator(func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            # Extract request and certificate_id from kwargs or args
            request = kwargs.get('request')
            certificate_id = kwargs.get('certificate_id')
            
            # Get audit service
            audit_service = kwargs.get('audit_service')
            
            if audit_service and request:
                await audit_service.log_action(
                    action=action,
                    actor_role="system",
                    actor_id=getattr(request, 'user_id', None),
                    certificate_id=certificate_id,
                    ip_address=request.client.host if request.client else None,
                    user_agent=request.headers.get('user-agent'),
                    details={"function": func.__name__}
                )
            
            return await func(*args, **kwargs)
        return wrapper
    return decorator