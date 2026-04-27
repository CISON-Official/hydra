from fastapi import APIRouter, Depends, HTTPException, Query
from fastapi.responses import StreamingResponse, JSONResponse
from typing import List, Optional
from uuid import UUID
from datetime import datetime, timezone
from io import BytesIO

from src.api.dependencies.database import get_audit_service, get_db
from src.main import get_qr_generator
from src.models.database.certificate import Certificate
from src.models.schemas.responses import (
    HolderCertificateResponse,
    CertificateStatusResponse,
)
from src.services.qr_generator import QRGeneratorService
from src.services.audit_service import AuditService
from src.core.storage.file_proxy import FileProxy
from src.api.dependencies.auth import verify_holder_token
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

router = APIRouter(prefix="/holder", tags=["Holder Operations"])


@router.get("/certificates", response_model=List[HolderCertificateResponse])
async def list_holder_certificates(
    email: str = Query(..., description="Holder email address"),
    include_expired: bool = Query(False),
    db: AsyncSession = Depends(get_db),
    audit_service: AuditService = Depends(get_audit_service),
):
    """
    List all certificates for a holder (identified by email)
    Note: In production, this would use proper auth with email verification
    """
    # Query certificates
    stmt = select(Certificate).where(Certificate.holder_email == email)

    if not include_expired:
        stmt = stmt.where(Certificate.expires_at > datetime.now(timezone.utc))
        stmt = stmt.where(Certificate.revoked_at.is_(None))

    stmt = stmt.order_by(Certificate.created_at.desc())

    result = await db.execute(stmt)
    certificates = result.scalars().all()

    # Log audit (with caution - don't log personal data excessively)
    await audit_service.log_action(
        certificate_id=None,
        action="list_certificates",
        actor_role="holder",
        actor_id=email,
        details={"count": len(certificates)},
    )

    return [
        HolderCertificateResponse(
            id=cert.id,
            certificate_title=cert.certificate_title,
            issuer_id=cert.issuer_id,
            issued_at=cert.created_at,
            expires_at=cert.expires_at,
            is_valid=not cert.revoked_at
            and cert.expires_at > datetime.now(timezone.utc),
            qr_code_available=bool(cert.qr_nonce),
        )
        for cert in certificates
    ]


@router.get("/certificates/{certificate_id}/qr")
async def download_holder_qr(
    certificate_id: UUID,
    email: str = Query(..., description="Holder email for verification"),
    db: AsyncSession = Depends(get_db),
    qr_service: QRGeneratorService = Depends(get_qr_generator),
    audit_service: AuditService = Depends(get_audit_service),
):
    """
    Download QR code for certificate (holder only)
    """
    # Verify holder owns this certificate
    stmt = select(Certificate).where(
        Certificate.id == certificate_id, Certificate.holder_email == email
    )
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        raise HTTPException(
            status_code=404, detail="Certificate not found or access denied"
        )

    # Generate QR URL and image
    qr_url = await qr_service.generate_qr_url(certificate_id, db)
    qr_image = await qr_service.generate_qr_image(qr_url)

    # Log audit
    await audit_service.log_action(
        certificate_id=certificate_id,
        action="download_qr",
        actor_role="holder",
        actor_id=email,
        details={"qr_url": qr_url[:50]},  # Log only prefix for privacy
    )

    return StreamingResponse(
        qr_image,
        media_type="image/png",
        headers={
            "Content-Disposition": f"attachment; filename=certificate_{certificate_id}_qr.png",
            "Cache-Control": "no-cache",
        },
    )


# @router.get(
#     "/certificates/{certificate_id}/status", response_model=CertificateStatusResponse
# )
# async def check_certificate_status(
#     certificate_id: UUID,
#     email: str = Query(..., description="Holder email"),
#     db: AsyncSession = Depends(get_db),
# ):
#     """
#     Check if certificate is still valid (for holders)
#     """
#     stmt = select(Certificate).where(
#         Certificate.id == certificate_id, Certificate.holder_email == email
#     )
#     result = await db.execute(stmt)
#     cert = result.scalar_one_or_none()

#     if not cert:
#         raise HTTPException(status_code=404, detail="Certificate not found")

#     now = datetime.now(timezone.utc)
#     is_valid = not cert.revoked_at and cert.expires_at > now
#     time_remaining = None

#     if is_valid:
#         time_remaining = int((cert.expires_at - now).total_seconds())

#     return CertificateStatusResponse(
#         certificate_id=cert.id,
#         is_valid=is_valid,
#         issued_at=cert.created_at,
#         expires_at=cert.expires_at,
#         time_remaining_seconds=time_remaining,
#         revoked_at=cert.revoked_at,
#         revocation_reason=cert.revocation_reason,
#     )


# @router.post("/certificates/{certificate_id}/request-renewal")
# async def request_renewal(
#     certificate_id: UUID,
#     email: str = Query(...),
#     db: AsyncSession = Depends(get_db),
#     audit_service: AuditService = Depends(get_audit_service),
# ):
#     """
#     Request certificate renewal (notifies issuer)
#     """
#     stmt = select(Certificate).where(
#         Certificate.id == certificate_id, Certificate.holder_email == email
#     )
#     result = await db.execute(stmt)
#     cert = result.scalar_one_or_none()

#     if not cert:
#         raise HTTPException(status_code=404, detail="Certificate not found")

#     # In production, this would send email/notification to issuer
#     await audit_service.log_action(
#         certificate_id=certificate_id,
#         action="request_renewal",
#         actor_role="holder",
#         actor_id=email,
#         details={
#             "issuer_id": str(cert.issuer_id),
#             "current_expiry": cert.expires_at.isoformat(),
#         },
#     )

#     return JSONResponse(
#         {
#             "message": "Renewal request submitted successfully",
#             "certificate_id": str(certificate_id),
#             "issuer_notified": True,
#         }
#     )
