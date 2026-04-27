from uuid import UUID
from datetime import datetime, timezone

from fastapi import HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from src.config import get_settings
from src.models.schemas.responses import VerificationResponse
from src.models.database.certificate import Certificate
from src.core.crypto.hmac_handler import HMACHandler
from src.core.crypto.token_manager import TokenManager
from src.core.cache.redis_manager import RedisManager


class VerificationService:
    def __init__(
        self,
        hmac_handler: HMACHandler,
        token_manager: TokenManager,
        redis_manager: RedisManager,
    ):
        self.hmac = hmac_handler
        self.token_manager = token_manager
        self.redis = redis_manager

    async def verify_certificate(
        self, certificate_id: UUID, signature: str, db: AsyncSession
    ) -> VerificationResponse:
        """Main verification flow"""

        # Get certificate with cache check
        cached = await self.redis.get_certificate_status(str(certificate_id))

        if cached:
            is_valid = cached["is_valid"]
            expires_at = datetime.fromisoformat(cached["expires_at"])
            cert = None  # type: ignore
        else:
            # Query database
            stmt = select(Certificate).where(Certificate.id == certificate_id)
            result = await db.execute(stmt)
            cert: Certificate = result.scalar_one_or_none()  # type: ignore

            if not cert:
                raise HTTPException(status_code=404, detail="Certificate not found")

            expires_at = cert.expires_at
            is_valid = not cert.revoked_at and expires_at > datetime.now(timezone.utc)

            await self.redis.cache_certificate_status(
                str(certificate_id), is_valid, expires_at
            )

        if not is_valid:
            raise HTTPException(
                status_code=410, detail="Certificate has expired or been revoked"
            )

        if not cert:
            # Re-fetch if we only had cached data
            stmt = select(Certificate).where(Certificate.id == certificate_id)
            result = await db.execute(stmt)
            cert: Certificate = result.scalar_one()

        if not self.hmac.verify_signature(certificate_id, cert.qr_nonce, signature):  # type: ignore
            raise HTTPException(status_code=401, detail="Invalid signature")

        session_token, ttl = self.token_manager.create_session_token(
            certificate_id, expires_at
        )

        return VerificationResponse(
            session_token=session_token,
            expires_in_seconds=ttl,
            file_endpoint=f"/api/certificates/{certificate_id}/content",
            certificate_title=cert.certificate_title,
            holder_name=cert.holder_name,
            expires_at=expires_at,
            is_valid=is_valid,
        )


settings = get_settings()


def get_verification_service():
    hmac_handler = HMACHandler(settings.secret_key)
    token_handler = TokenManager(settings.secret_key)
    redis_manager = RedisManager(settings.redis_url, settings.redis_cache_ttl_seconds)
    return VerificationService(hmac_handler, token_handler, redis_manager)
