from io import BytesIO
from uuid import UUID
from typing import Annotated

import segno
from fastapi import Depends, Request
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, update

from src.config import Settings
from src.core.crypto.hmac_handler import HMACHandler
from src.models.database.certificate import Certificate


class QRGeneratorService:
    def __init__(self, base_url: str, hmac_handler: HMACHandler):
        self.base_url = base_url.rstrip("/")
        self.hmac = hmac_handler

    async def generate_qr_url(
        self, certificate_id: UUID, db: AsyncSession, force_regenerate: bool = False
    ) -> str:
        """Generate QR code URL for certificate"""

        # Get certificate
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()

        if not cert:
            raise ValueError("Certificate not found")

        # Generate nonce if needed
        if force_regenerate or not cert.qr_nonce:
            cert.qr_nonce = self.hmac.generate_nonce()
            await db.commit()
            await db.refresh(cert)

        # Generate HMAC signature
        signature = self.hmac.generate_signature(certificate_id, cert.qr_nonce)

        # Build verification URL
        qr_url = f"{self.base_url}/verify/{certificate_id}?hmac={signature}"

        return qr_url

    async def generate_qr_image(self, url: str, scale: int = 8) -> BytesIO:
        """Generate QR code as PNG bytes"""
        qr = segno.make(url, error="H")
        buffer = BytesIO()
        qr.save(buffer, kind="png", scale=scale)
        buffer.seek(0)
        return buffer

    async def regenerate_qr(
        self, certificate_id: UUID, db: AsyncSession
    ) -> tuple[str, bool]:
        """Regenerate QR code (invalidates old one)"""

        # Update nonce
        stmt = (
            update(Certificate)
            .where(Certificate.id == certificate_id)
            .values(qr_nonce=self.hmac.generate_nonce())
        )

        await db.execute(stmt)
        await db.commit()

        # Generate new URL
        new_url = await self.generate_qr_url(certificate_id, db, force_regenerate=True)

        return new_url, True


def get_base_url(request=Request) -> str:
    return str(request.base_url)


def get_qr_generator() -> QRGeneratorService:
    setting = Settings()
    hmac_handler = HMACHandler(setting.secret_key)
    return QRGeneratorService(setting.qr_base_url, hmac_handler)
