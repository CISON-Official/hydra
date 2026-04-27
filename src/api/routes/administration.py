from fastapi import (
    APIRouter,
    Depends,
    HTTPException,
    UploadFile,
    File,
    Response,
    Form,
    Query,
    BackgroundTasks,
)
from fastapi.responses import JSONResponse
from typing import Optional, List
from uuid import UUID
from datetime import datetime, timezone
import hashlib

from src.services.certificate_service import CertificateUpdateService
from src.api.dependencies.database import get_audit_service, get_db
from src.models.database.certificate import Certificate, CertificateVersion
from src.models.database.audit_log import AuditLog
from src.models.schemas.requests import CertificateUploadRequest, RevocationRequest
from src.models.schemas.responses import (
    CertificateUploadResponse,
    CertificateDetailResponse,
    CertificateListResponse,
    RevocationResponse,
    QRRotationResponse,
)
from src.services.qr_generator import QRGeneratorService, get_qr_generator
from src.services.revocation_service import RevocationService, get_revocation_service
from src.services.audit_service import AuditService
from src.core.storage.s3_client import S3Client, get_s3_client
from src.core.crypto.hash_validator import HashValidator, get_hash_validator
from src.api.dependencies.auth import require_role, get_current_user
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import and_, select, update, delete
from sqlalchemy.orm import selectinload

router = APIRouter(prefix="/certificates", tags=["Administration"])


def get_certificate_update_service():
    return CertificateUpdateService(
        s3_client=Depends(get_s3_client),
        redis_manager=Depends(get_db),
        audit_service=Depends(get_audit_service),
        qr_service=Depends(get_qr_generator),
        hash_validator=Depends(get_hash_validator),
    )


@router.post("/upload", response_model=CertificateUploadResponse)
async def upload_certificate(
    file: UploadFile = File(..., description="Certificate PDF file"),
    holder_email: str = Form(..., description="Email of certificate holder"),
    holder_name: str = Form(..., description="Full name of certificate holder"),
    certificate_title: str = Form(..., description="Title of the certificate"),
    expires_at: str = Form(..., description="Expiration date (ISO format)"),
    regenerate_qr: bool = Form(False, description="Force QR regeneration"),
    background_tasks: BackgroundTasks = None,  # type: ignore
    db: AsyncSession = Depends(get_db),
    s3_client: S3Client = Depends(get_s3_client),
    qr_service: QRGeneratorService = Depends(get_qr_generator),
    hash_validator: HashValidator = Depends(get_hash_validator),
    audit_service: AuditService = Depends(get_audit_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Upload a new certificate (Issuer only)
    """
    # Validate file size (max 10MB)
    file_size = 0
    file_content = await file.read()
    file_size = len(file_content)

    if file_size > 10 * 1024 * 1024:  # 10MB
        raise HTTPException(status_code=400, detail="File too large (max 10MB)")

    # Validate file type
    if not file.content_type == "application/pdf":
        raise HTTPException(status_code=400, detail="Only PDF files are allowed")

    # Parse expiry date
    try:
        expiry_datetime = datetime.fromisoformat(expires_at.replace("Z", "+00:00"))
        if expiry_datetime.tzinfo is None:
            expiry_datetime = expiry_datetime.replace(tzinfo=timezone.utc)
    except ValueError:
        raise HTTPException(status_code=400, detail="Invalid expiry date format")

    if expiry_datetime <= datetime.now(timezone.utc):
        raise HTTPException(status_code=400, detail="Expiry date must be in the future")

    # Calculate file hash
    file_hash = hashlib.sha256(file_content).hexdigest()

    # Check for duplicate by hash (optional, based on requirements)
    stmt = select(Certificate).where(Certificate.file_hash == file_hash)
    result = await db.execute(stmt)
    existing = result.scalar_one_or_none()
    if existing:
        raise HTTPException(
            status_code=409, detail="Certificate already exists with same content"
        )

    # Generate S3 key
    import uuid

    s3_key = f"certificates/{uuid.uuid4()}.pdf"

    # Upload to S3
    try:
        etag, uploaded_size = await s3_client.upload_file(
            file_content, s3_key, file.content_type
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to upload file: {str(e)}")

    # Create certificate record
    certificate_id = uuid.uuid4()
    new_certificate = Certificate(
        id=certificate_id,
        issuer_id=current_user["id"],
        holder_email=holder_email,
        holder_name=holder_name,
        certificate_title=certificate_title,
        file_hash=file_hash,
        s3_key=s3_key,
        file_size_bytes=uploaded_size,
        content_type=file.content_type,
        expires_at=expiry_datetime,
        qr_nonce=qr_service.hmac.generate_nonce() if not regenerate_qr else None,
    )

    db.add(new_certificate)
    await db.commit()
    await db.refresh(new_certificate)

    # Generate QR code URL
    qr_url = await qr_service.generate_qr_url(
        certificate_id, db, force_regenerate=regenerate_qr
    )

    # Generate QR image (async)
    if background_tasks:
        background_tasks.add_task(qr_service.generate_qr_image, qr_url)

    # Log audit
    await audit_service.log_action(
        certificate_id=certificate_id,
        action="upload",
        actor_role="issuer",
        actor_id=str(current_user["id"]),
        details={
            "title": certificate_title,
            "holder_email": holder_email,
            "file_size": file_size,
        },
    )

    return CertificateUploadResponse(
        certificate_id=certificate_id,
        qr_code_url=qr_url,
        verification_url=f"{qr_service.base_url}/verify/{certificate_id}",
        expires_at=expiry_datetime,
        holder_name=holder_name,
        certificate_title=certificate_title,
    )


@router.get("/{certificate_id}", response_model=CertificateDetailResponse)
async def get_certificate_details(
    certificate_id: UUID,
    db: AsyncSession = Depends(get_db),
    current_user=Depends(require_role(["issuer", "admin", "verifier"])),
):
    """
    Get detailed certificate information
    """
    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        raise HTTPException(status_code=404, detail="Certificate not found")

    # Check access: issuers can only see their own certificates
    if current_user["role"] == "issuer" and cert.issuer_id != current_user["id"]:
        raise HTTPException(status_code=403, detail="Access denied")

    # Get verification stats from audit logs
    stmt = select(AuditLog).where(
        AuditLog.certificate_id == certificate_id,
        AuditLog.action.in_(["verify", "view"]),
    )
    result = await db.execute(stmt)
    verification_logs = result.scalars().all()

    return CertificateDetailResponse(
        id=cert.id,
        holder_email=cert.holder_email,
        holder_name=cert.holder_name,
        certificate_title=cert.certificate_title,
        created_at=cert.created_at,
        expires_at=cert.expires_at,
        revoked_at=cert.revoked_at,
        revocation_reason=cert.revocation_reason,
        file_size_bytes=cert.file_size_bytes,
        verification_count=len(verification_logs),
        is_valid=not cert.revoked_at and cert.expires_at > datetime.now(timezone.utc),
    )


@router.get("/", response_model=List[CertificateListResponse])
async def list_certificates(
    skip: int = Query(0, ge=0),
    limit: int = Query(100, ge=1, le=1000),
    include_expired: bool = Query(False),
    db: AsyncSession = Depends(get_db),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    List certificates (with pagination)
    """
    stmt = select(Certificate)

    # Filter by issuer
    if current_user["role"] == "issuer":
        stmt = stmt.where(Certificate.issuer_id == current_user["id"])

    # Filter expired
    if not include_expired:
        stmt = stmt.where(Certificate.expires_at > datetime.now(timezone.utc))
        stmt = stmt.where(Certificate.revoked_at.is_(None))

    # Order and paginate
    stmt = stmt.order_by(Certificate.created_at.desc()).offset(skip).limit(limit)

    result = await db.execute(stmt)
    certificates = result.scalars().all()

    return [
        CertificateListResponse(
            id=cert.id,
            certificate_title=cert.certificate_title,
            holder_name=cert.holder_name,
            created_at=cert.created_at,
            expires_at=cert.expires_at,
            is_valid=not cert.revoked_at
            and cert.expires_at > datetime.now(timezone.utc),
        )
        for cert in certificates
    ]


@router.post("/{certificate_id}/revoke", response_model=RevocationResponse)
async def revoke_certificate(
    certificate_id: UUID,
    request: RevocationRequest,
    db: AsyncSession = Depends(get_db),
    revocation_service: RevocationService = Depends(get_revocation_service),
    audit_service: AuditService = Depends(get_audit_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Revoke a certificate (Issuer or Admin only)
    """
    # Check ownership
    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        raise HTTPException(status_code=404, detail="Certificate not found")

    if current_user["role"] == "issuer" and cert.issuer_id != current_user["id"]:
        raise HTTPException(
            status_code=403, detail="Cannot revoke certificates from other issuers"
        )

    # Perform revocation
    success = await revocation_service.revoke_certificate(
        certificate_id=certificate_id,
        reason=request.reason,
        revoked_by=str(current_user["id"]),
        db=db,
    )

    if not success:
        raise HTTPException(
            status_code=400, detail="Certificate already revoked or expired"
        )

    # Log audit
    await audit_service.log_action(
        certificate_id=certificate_id,
        action="revoke",
        actor_role=current_user["role"],
        actor_id=str(current_user["id"]),
        details={"reason": request.reason},
    )

    return RevocationResponse(
        certificate_id=certificate_id,
        revoked_at=datetime.now(timezone.utc),
        reason=request.reason,
        success=True,
    )


@router.post("/{certificate_id}/rotate-qr", response_model=QRRotationResponse)
async def rotate_qr_code(
    certificate_id: UUID,
    db: AsyncSession = Depends(get_db),
    qr_service: QRGeneratorService = Depends(get_qr_generator),
    audit_service: AuditService = Depends(get_audit_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Regenerate QR code (invalidates old one)
    """
    # Check ownership
    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        raise HTTPException(status_code=404, detail="Certificate not found")

    if current_user["role"] == "issuer" and cert.issuer_id != current_user["id"]:
        raise HTTPException(status_code=403, detail="Access denied")

    # Regenerate QR
    new_url, invalidated = await qr_service.regenerate_qr(certificate_id, db)

    # Log audit
    await audit_service.log_action(
        certificate_id=certificate_id,
        action="rotate_qr",
        actor_role=current_user["role"],
        actor_id=str(current_user["id"]),
        details={"old_qr_invalidated": invalidated},
    )

    return QRRotationResponse(
        certificate_id=certificate_id,
        new_qr_code_url=new_url,
        old_qr_invalidated=invalidated,
        regenerated_at=datetime.now(timezone.utc),
    )


@router.put("/{certificate_id}/file")
async def update_certificate_file(
    certificate_id: UUID,
    new_file: UploadFile = File(..., description="Updated PDF file"),
    update_reason: str = Form(..., description="Reason for update"),
    preserve_qr: bool = Form(True, description="Keep existing QR code"),
    notify_holder: bool = Form(True, description="Send notification to holder"),
    db: AsyncSession = Depends(get_db),
    update_service: CertificateUpdateService = Depends(get_certificate_update_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Update certificate file with versioning

    - Preserves version history
    - Can optionally regenerate QR code
    - Notifies holder
    - Maintains audit trail
    """

    # Check ownership for issuers
    if current_user["role"] == "issuer":
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()

        if not cert or cert.issuer_id != current_user["id"]:
            raise HTTPException(
                status_code=403, detail="Cannot update certificates from other issuers"
            )

    result = await update_service.update_certificate_file(
        certificate_id=certificate_id,
        new_file=new_file,
        update_reason=update_reason,
        updated_by=current_user["id"],
        db=db,
        preserve_qr=preserve_qr,
        notify_holder=notify_holder,
    )

    return result


@router.post("/{certificate_id}/rollback/{version}")
async def rollback_certificate_version(
    certificate_id: UUID,
    version: int,
    rollback_reason: str = Form(...),
    db: AsyncSession = Depends(get_db),
    update_service: CertificateUpdateService = Depends(get_certificate_update_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Rollback certificate to a previous version
    """

    result = await update_service.rollback_to_version(
        certificate_id=certificate_id,
        target_version=version,
        rollback_reason=rollback_reason,
        rolled_back_by=current_user["id"],
        db=db,
    )

    return result


@router.get("/{certificate_id}/versions")
async def get_certificate_versions(
    certificate_id: UUID,
    limit: int = Query(50, ge=1, le=100),
    db: AsyncSession = Depends(get_db),
    update_service: CertificateUpdateService = Depends(get_certificate_update_service),
    current_user=Depends(require_role(["issuer", "admin", "verifier"])),
):
    """
    Get version history for a certificate
    """

    history = await update_service.get_version_history(
        certificate_id=certificate_id, db=db, limit=limit
    )

    return {
        "certificate_id": certificate_id,
        "total_versions": len(history),
        "versions": history,
    }


@router.get("/{certificate_id}/compare")
async def compare_certificate_versions(
    certificate_id: UUID,
    version_a: int = Query(..., description="First version number (0 for current)"),
    version_b: int = Query(..., description="Second version number (0 for current)"),
    db: AsyncSession = Depends(get_db),
    update_service: CertificateUpdateService = Depends(get_certificate_update_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Compare two versions of a certificate
    """

    comparison = await update_service.compare_versions(
        certificate_id=certificate_id, version_a=version_a, version_b=version_b, db=db
    )

    return comparison


@router.post("/{certificate_id}/extend")
async def extend_certificate(
    certificate_id: UUID,
    new_expiry_date: str = Form(..., description="New expiry date (ISO format)"),
    extension_reason: str = Form(..., description="Reason for extension"),
    update_file: Optional[UploadFile] = File(None, description="Optional new file"),
    db: AsyncSession = Depends(get_db),
    update_service: CertificateUpdateService = Depends(get_certificate_update_service),
    current_user=Depends(require_role(["issuer", "admin"])),
):
    """
    Extend certificate expiry and optionally update file
    """

    try:
        new_expiry = datetime.fromisoformat(new_expiry_date.replace("Z", "+00:00"))
        if new_expiry.tzinfo is None:
            new_expiry = new_expiry.replace(tzinfo=timezone.utc)
    except ValueError:
        raise HTTPException(status_code=400, detail="Invalid expiry date format")

    result = await update_service.extend_expiry_with_update(
        certificate_id=certificate_id,
        new_expiry_date=new_expiry,
        extension_reason=extension_reason,
        updated_by=current_user["id"],
        update_file=update_file,
        db=db,
    )

    return result


@router.get("/{certificate_id}/download/version/{version}")
async def download_certificate_version(
    certificate_id: UUID,
    version: int,
    db: AsyncSession = Depends(get_db),
    current_user=Depends(require_role(["issuer", "admin", "holder"])),
    s3_client: S3Client = Depends(get_s3_client),
    audit_service: AuditService = Depends(get_audit_service),
):
    """
    Download a specific version of a certificate
    """

    # Get version
    if version == 0:  # Current version
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()

        if not cert:
            raise HTTPException(status_code=404, detail="Certificate not found")

        s3_key = cert.s3_key
        file_hash = cert.file_hash
    else:
        stmt = select(CertificateVersion).where(
            and_(
                CertificateVersion.certificate_id == certificate_id,
                CertificateVersion.version_number == version,
            )
        )
        result = await db.execute(stmt)
        version_data = result.scalar_one_or_none()

        if not version_data:
            raise HTTPException(status_code=404, detail="Version not found")

        s3_key = version_data.s3_key
        file_hash = version_data.file_hash

    # Get file from S3
    try:
        file_content = await s3_client.get_file(s3_key)

        # Log download
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="download_version",
            actor_role=current_user["role"],
            actor_id=str(current_user["id"]),
            details={"version": version, "file_hash": file_hash[:8]},
        )

        return Response(
            content=file_content,
            media_type="application/pdf",
            headers={
                "Content-Disposition": f"inline; filename=certificate_{certificate_id}_v{version}.pdf",
                "X-File-Hash": file_hash,
                "X-Version": str(version),
            },
        )

    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to download: {str(e)}")
