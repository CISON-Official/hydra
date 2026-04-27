from uuid import UUID
from typing import Optional
from datetime import datetime, timezone

from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import AsyncSession

from src.config import Settings
from src.models.database.certificate import Certificate
from src.core.cache.redis_manager import RedisManager


class RevocationService:
    def __init__(self, redis_manager: RedisManager):
        self.redis = redis_manager

    async def revoke_certificate(
        self, certificate_id: UUID, reason: str, revoked_by: str, db: AsyncSession
    ) -> bool:
        """
        Revoke a certificate

        Returns:
            bool: True if revoked successfully, False if already revoked/expired
        """
        # Get certificate
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()

        if not cert:
            raise ValueError("Certificate not found")

        # Check if already revoked
        if cert.revoked_at is not None:
            return False

        # Check if already expired
        if cert.expires_at < datetime.now(timezone.utc):
            return False

        # Update certificate
        stmt = (
            update(Certificate)
            .where(Certificate.id == certificate_id)
            .values(revoked_at=datetime.now(timezone.utc), revocation_reason=reason)
        )

        await db.execute(stmt)
        await db.commit()

        # Invalidate cache
        await self.redis.invalidate_certificate(str(certificate_id))

        # Invalidate any active session tokens (optional)
        pattern = f"session:*:{certificate_id}"
        async for key in self.redis.client.scan_iter(pattern):
            await self.redis.client.delete(key)

        return True

    async def check_certificate_status(
        self, certificate_id: UUID, db: AsyncSession
    ) -> dict:
        """
        Check if certificate is valid, with caching
        """
        # Check cache first
        cached = await self.redis.get_certificate_status(str(certificate_id))

        if cached:
            return {
                "is_valid": cached["is_valid"],
                "expires_at": cached["expires_at"],
                "revoked_at": cached.get("revoked_at"),
                "source": "cache",
            }

        # Query database
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()

        if not cert:
            return {"is_valid": False, "error": "Certificate not found"}

        now = datetime.now(timezone.utc)
        is_valid = not cert.revoked_at and cert.expires_at > now

        # Cache result
        await self.redis.cache_certificate_status(
            str(certificate_id), is_valid, cert.expires_at
        )

        return {
            "is_valid": is_valid,
            "expires_at": cert.expires_at.isoformat(),
            "revoked_at": cert.revoked_at.isoformat() if cert.revoked_at else None,
            "revocation_reason": cert.revocation_reason,
            "source": "database",
        }

    async def bulk_revoke_by_issuer(
        self, issuer_id: UUID, reason: str, db: AsyncSession
    ) -> int:
        """
        Revoke all active certificates issued by a specific issuer

        Returns:
            int: Number of certificates revoked
        """
        now = datetime.now(timezone.utc)

        stmt = (
            update(Certificate)
            .where(
                Certificate.issuer_id == issuer_id,
                Certificate.revoked_at.is_(None),
                Certificate.expires_at > now,
            )
            .values(
                revoked_at=now, revocation_reason=f"Bulk revocation by issuer: {reason}"
            )
            .returning(Certificate.id)
        )

        result = await db.execute(stmt)
        await db.commit()

        revoked_ids = result.scalars().all()

        # Invalidate cache for all revoked certificates
        for cert_id in revoked_ids:
            await self.redis.invalidate_certificate(str(cert_id))

        return len(revoked_ids)

    async def reinstate_certificate(
        self, certificate_id: UUID, db: AsyncSession
    ) -> bool:
        """
        Reinstate a revoked certificate (admin only)
        """
        stmt = (
            update(Certificate)
            .where(
                Certificate.id == certificate_id, Certificate.revoked_at.is_not(None)
            )
            .values(revoked_at=None, revocation_reason=None)
        )

        result = await db.execute(stmt)
        await db.commit()

        if result.rowcount > 0:  # type: ignore
            await self.redis.invalidate_certificate(str(certificate_id))
            return True

        return False


def get_revocation_service():
    settings = Settings()
    redis_manager = RedisManager(settings.redis_url, settings.redis_cache_ttl_seconds)

    return RevocationService(redis_manager)
